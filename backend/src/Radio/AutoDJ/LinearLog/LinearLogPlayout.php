<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\LinearLogPreviewContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plays the saved 24-hour linear log in order.
 *
 * Runs at the same BuildQueue step as the random picker (after listener
 * requests and AI DJ, which stay live). While the log has a line ready, the
 * Clock Wheel and random pickers stand down. Validators still run on the
 * log's pick: a DMCA rejection makes the next attempt fall back to the normal
 * pickers and the replacement is linked back to the log line; a Top-of-Hour
 * swap changes the queue row and is written back when it airs.
 */
final class LinearLogPlayout implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** Last log line offered, to detect a validator rejecting it. */
    private ?int $offeredEntryId = null;
    private ?int $offeredAt = null;

    /** @var array<int, int> expected-play timestamp => log line that failed validation there */
    private array $replacementFor = [];

    public function __construct(
        private readonly LinearLogPreviewContext $previewContext,
        private readonly LinearLogStore $store,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['supplyFromLog', 0],
                ['linkSelection', -190],
            ],
        ];
    }

    public static function isPlayoutEnabled(Station $station): bool
    {
        $config = $station->backend_config;
        return $config->linear_log_enabled && $config->linear_log_playout_enabled;
    }

    /**
     * True when the log, not the Clock Wheel or random pickers, chooses this slot.
     */
    public function ownsSelection(BuildQueue $event): bool
    {
        $station = $event->getStation();
        if (
            !self::isPlayoutEnabled($station)
            || $this->previewContext->isActive()
            || $event->isInterrupting()
        ) {
            return false;
        }

        if (isset($this->replacementFor[$event->getExpectedPlayTime()->getTimestamp()])) {
            return false;
        }

        return null !== $this->findNextPlannedId($station);
    }

    public function supplyFromLog(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs()) || !$this->ownsSelection($event)) {
            return;
        }

        $station = $event->getStation();
        $expected = $event->getExpectedPlayTime();
        $at = $expected->getTimestamp();

        $entry = $this->takeNext($station, $expected);
        if (!$entry instanceof StationLogEntry) {
            return;
        }

        // Same line offered for the same slot again: a validator (DMCA)
        // rejected it. Let the normal pickers choose; linkSelection() ties the
        // replacement back to this line.
        if ($this->offeredEntryId === $entry->id && $this->offeredAt === $at) {
            $this->replacementFor[$at] = $entry->id;
            $this->offeredEntryId = null;
            $this->logger->notice(
                'Linear Log: planned line failed validation; AutoDJ picks a replacement.',
                ['log_entry_id' => $entry->id, 'planned' => $entry->text]
            );
            return;
        }

        $row = $this->materialize($station, $entry, $expected);
        $this->offeredEntryId = $entry->id;
        $this->offeredAt = $at;

        $event->setNextSongs($row);
    }

    public function linkSelection(BuildQueue $event): void
    {
        $at = $event->getExpectedPlayTime()->getTimestamp();
        $rows = $event->getNextSongs();
        if (1 !== count($rows)) {
            return;
        }
        $row = $rows[0];

        $entryId = $row->log_entry_id;
        $note = null;

        if (null === $entryId && isset($this->replacementFor[$at])) {
            $entryId = $this->replacementFor[$at];
            $row->log_entry_id = $entryId;
            $note = 'Replaced at air time: the planned song failed the DMCA rules';
        }
        unset($this->replacementFor[$at]);

        if (null === $entryId) {
            return;
        }

        $entry = $this->em->find(StationLogEntry::class, $entryId);
        if (!$entry instanceof StationLogEntry) {
            return;
        }

        $entry->status = StationLogEntry::STATUS_QUEUED;
        if (null !== $note) {
            $entry->note = $note;
        }
        $this->em->persist($entry);

        if ($this->offeredEntryId === $entryId) {
            $this->offeredEntryId = null;
        }
    }

    /**
     * Next planned line, dropping any left over from an hour that has already
     * ended ("hit the post": the new hour opens with its own first line).
     */
    private function takeNext(Station $station, DateTimeImmutable $expected): ?StationLogEntry
    {
        $hourStart = CarbonImmutable::instance($expected)
            ->setTimezone($station->getTimezoneObject())
            ->startOfHour()
            ->getTimestamp();

        $dropped = $this->em->createQuery(
            <<<'DQL'
                UPDATE App\Entity\StationLogEntry e
                SET e.status = :dropped, e.note = :note
                WHERE e.station = :station
                AND e.status = :planned
                AND e.planned_at < :hourStart
            DQL
        )->setParameter('dropped', StationLogEntry::STATUS_DROPPED)
            ->setParameter('note', 'Dropped: its hour ran long')
            ->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('hourStart', $hourStart)
            ->execute();

        if ($dropped > 0) {
            $this->logger->notice('Linear Log: dropped lines left over from an hour that ended.', [
                'count' => $dropped,
            ]);
        }

        for ($i = 0; $i < 20; $i++) {
            /** @var StationLogEntry|null $entry */
            $entry = $this->em->createQuery(
                <<<'DQL'
                    SELECT e FROM App\Entity\StationLogEntry e
                    WHERE e.station = :station AND e.status = :planned
                    ORDER BY e.sequence ASC
                DQL
            )->setParameter('station', $station)
                ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (!$entry instanceof StationLogEntry) {
                return null;
            }

            if (null !== $entry->media) {
                return $entry;
            }

            $entry->status = StationLogEntry::STATUS_DROPPED;
            $entry->note = 'Dropped: the planned file was removed from the library';
            $this->em->persist($entry);
            $this->em->flush();
        }

        return null;
    }

    private function findNextPlannedId(Station $station): ?int
    {
        $id = $this->em->createQuery(
            <<<'DQL'
                SELECT e.id FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.status = :planned
            DQL
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return is_array($id) ? (int)$id['id'] : null;
    }

    private function materialize(
        Station $station,
        StationLogEntry $entry,
        DateTimeInterface $expected
    ): StationQueue {
        $this->logger->info('Linear Log: playing the next planned line.', [
            'log_entry_id' => $entry->id,
            'planned_at' => $entry->planned_at,
            'text' => $entry->text,
        ]);

        return $this->store->toQueueRow($station, $entry, $expected);
    }
}
