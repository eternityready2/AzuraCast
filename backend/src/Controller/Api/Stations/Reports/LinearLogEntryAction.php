<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

/**
 * Hand edits on the saved linear log ("live assist"): lock, move, remove or
 * replace a line that has not aired. A queued line is replaced inside its own
 * queue slot; a planned line is re-timed by a background build afterwards. A
 * line whose track Liquidsoap has already loaded can no longer change.
 */
final class LinearLogEntryAction
{
    use EntityManagerAwareTrait;

    /** How far past the ID a hand pick may run: the Top-of-Hour cut grace. */
    private const float ID_CUT_GRACE_SECONDS = 1.0;

    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly TopOfHourClock $topOfHourClock,
        private readonly AiredLength $airedLength,
    ) {
    }

    /** @param array<string, string> $params */
    public function editAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        if (!LinearLogPlayout::isPlayoutEnabled($station)) {
            throw new InvalidArgumentException('Hand edits need "Log Controls Playout" turned on.');
        }

        $entry = $this->em->find(StationLogEntry::class, (int)($params['entry_id'] ?? 0));
        if (!$entry instanceof StationLogEntry || $entry->station->id !== $station->id) {
            throw new InvalidArgumentException('Log line not found.');
        }
        // A line that has aired is history and never changes. A queued line can
        // still be pulled or swapped, the way an operator kills the next item on
        // FM automation, until Liquidsoap has loaded its track (see below). Only
        // re-ordering needs the line to still be unqueued, since moving a row the
        // queue already holds would not change what plays next.
        if (!$entry->isOpen()) {
            throw new InvalidArgumentException('This line has already aired and can no longer be changed.');
        }

        $edit = $params['edit'] ?? '';
        $isQueued = StationLogEntry::STATUS_QUEUED === $entry->status;
        if ($isQueued && in_array($edit, ['up', 'down'], true)) {
            throw new InvalidArgumentException(
                'This line is already queued to play next; remove or replace it instead of moving it.'
            );
        }

        $data = (array)$request->getParsedBody();

        // One short transaction holding the queue row, so Liquidsoap cannot load
        // it between the check below and the edit. Not wrapInTransaction(): it
        // closes the EntityManager on any exception, refusals included.
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $needsRebuild = $this->applyEdit($station, $entry, $edit, $data);
            $this->em->flush();
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        if ($needsRebuild) {
            $this->refresh($station);
        }

        return $response->withJson(['success' => true]);
    }

    /**
     * @param array<string, mixed> $data
     * @return bool whether the log needs a background re-time afterwards
     */
    private function applyEdit(Station $station, StationLogEntry $entry, string $edit, array $data): bool
    {
        $queueRow = $this->findQueueRow($station, $entry);

        // Liquidsoap takes the next track as soon as the one before it starts:
        // behind a long show, up to an hour before it airs. Once it holds the
        // file, nothing here changes what plays. Deleting the queue row anyway
        // left the track to air with no as-run line, and the queue projection
        // pulled the next line forward into its slot: "The Gospel", loaded at
        // 11:00:36 behind the 11:00 Spotlight and replaced by hand at 11:59,
        // aired regardless, while the log showed the promo after it at 11:59:24
        // (2026-10-07).
        if (null !== $queueRow && $queueRow->sent_to_autodj && in_array($edit, ['remove', 'replace'], true)) {
            throw new InvalidArgumentException(sprintf(
                'This line is already loaded in the on-air player and plays at %s as planned. '
                . 'It can no longer be removed or replaced.',
                $this->stationTime($station, $queueRow->timestamp_played)
            ));
        }

        $needsRebuild = true;

        switch ($edit) {
            // A lock changes no timing, and the page reads it from the saved
            // line, so it needs no rebuild.
            case 'lock':
                $entry->is_locked = true;
                $needsRebuild = false;
                break;

            case 'unlock':
                $entry->is_locked = false;
                $needsRebuild = false;
                break;

            case 'up':
            case 'down':
                $this->move($station, $entry, 'up' === $edit);
                break;

            case 'remove':
                if (null !== $queueRow) {
                    $this->em->remove($queueRow);
                }
                // Kept as a dropped line, so the log shows what was planned and
                // that it was taken out by hand; deleted, it left an unexplained
                // hole. LinearLogRefill puts a song of about the same length in
                // the slot within a minute, so nothing else in the log moves and
                // no rebuild runs.
                $entry->status = StationLogEntry::STATUS_DROPPED;
                $entry->note = 'Dropped: removed by hand';
                $entry->queue_id = null;
                $needsRebuild = false;
                break;

            case 'replace':
                $media = $this->em->find(StationMedia::class, (int)($data['media_id'] ?? 0));
                if (
                    !$media instanceof StationMedia
                    || $media->storage_location->id !== $station->media_storage_location->id
                ) {
                    throw new InvalidArgumentException('Pick a file from this station\'s library.');
                }
                if (null !== $queueRow) {
                    $this->assertEndsBeforeId($station, $queueRow, $media);
                }

                $planned = $entry->text;
                $entry->media = $media;
                $entry->title = $media->title;
                $entry->artist = $media->artist;
                $entry->text = mb_substr((string)$media->text, 0, 255);
                $entry->duration = max(1.0, $media->getCalculatedLength());
                // Old clock wheel caps belonged to the old song.
                $entry->payload = [
                    'song_id' => $media->song_id,
                    'album' => $media->album,
                    'media_type' => $media->type,
                ];
                $entry->note = mb_substr('Replaced by hand; planned: ' . ($planned ?? 'unknown'), 0, 255);
                // A hand-picked song must not be re-planned by a rebuild or the
                // Top-of-Hour swap.
                $entry->is_locked = true;

                if (null !== $queueRow) {
                    // Swap the song inside its own queue slot, as the Top-of-Hour
                    // swap does: same place in the queue, same air time. Turning
                    // the line back into a planned one sent it to the end of the
                    // live queue instead -- "sonicflood liner 2", picked for
                    // 11:59:24, was re-planned to 13:03:22 (2026-10-07). The page
                    // shows the swap from the live queue, so no rebuild is needed.
                    $this->replaceQueuedSong($queueRow, $media);
                    $entry->queue_id = $queueRow->id;
                    $needsRebuild = false;
                } else {
                    // Not queued yet: it stays part of the plan, and playout
                    // takes the new song when its turn comes.
                    $entry->status = StationLogEntry::STATUS_PLANNED;
                    $entry->queue_id = null;
                }
                break;

            default:
                throw new InvalidArgumentException('Unknown edit.');
        }

        // Changes are only saved for entities passed to persist().
        $this->em->persist($entry);

        return $needsRebuild;
    }

    /**
     * Library search for the Replace picker.
     */
    public function mediaAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $query = trim((string)$request->getParam('q', ''));
        if (mb_strlen($query) < 2) {
            return $response->withJson([]);
        }

        /** @var StationMedia[] $media */
        $media = $this->em->createQuery(
            <<<'DQL'
                SELECT sm FROM App\Entity\StationMedia sm
                WHERE sm.storage_location = :storageLocation
                AND (sm.title LIKE :q OR sm.artist LIKE :q OR sm.text LIKE :q)
                ORDER BY sm.artist ASC, sm.title ASC
            DQL
        )->setParameter('storageLocation', $station->media_storage_location)
            ->setParameter('q', '%' . addcslashes($query, '%_') . '%')
            ->setMaxResults(25)
            ->getResult();

        return $response->withJson(array_map(
            static fn(StationMedia $sm): array => [
                'id' => $sm->id,
                'title' => $sm->title,
                'artist' => $sm->artist,
                'text' => $sm->text,
                'length' => $sm->getCalculatedLength(),
                'type' => $sm->type,
            ],
            $media
        ));
    }

    /**
     * The unplayed queue row a line became, if any, locked for the rest of the
     * edit's transaction. A row that already started playing is on air, and
     * history.
     */
    private function findQueueRow(Station $station, StationLogEntry $entry): ?StationQueue
    {
        $row = $this->em->createQuery(
            <<<'DQL'
                SELECT sq FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.log_entry_id = :entryId AND sq.is_played = 0
                ORDER BY sq.id DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('entryId', $entry->id)
            ->setMaxResults(1)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // The locked read must win over any copy already in memory.
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof StationQueue ? $row : null;
    }

    /**
     * Put $media into an unsent queue row in place, keeping its slot and its
     * link to the log line. Caps, stretch targets and Clock Wheel settings were
     * sized for the old song, as on the line itself.
     */
    private function replaceQueuedSong(StationQueue $row, StationMedia $media): void
    {
        $row->setSong($media);
        // Also resets the row's duration to the new file's length.
        $row->media = $media;
        // A custom URI (an AI DJ clip, a stream) would still be what airs, and
        // a listener request stays pending, to be queued again later.
        $row->autodj_custom_uri = null;
        $row->request = null;
        $row->clock_wheel = null;
        $row->clock_wheel_max_play_seconds = null;
        $row->clock_wheel_schedule_mode = null;
        $row->clock_wheel_enforce_cap = false;
        $row->clock_wheel_stretch_ratio = null;
        $row->clock_wheel_legal_id_substitute = false;
        $row->hour_boundary_enforce_cap = false;
        $row->hour_boundary_max_play_seconds = null;
        $this->em->persist($row);
    }

    /**
     * A hand pick for a queued slot must end on the Top-of-Hour ID, or the ID
     * cuts it; the swap no longer second-guesses a hand pick, so say so now.
     */
    private function assertEndsBeforeId(Station $station, StationQueue $row, StationMedia $media): void
    {
        if (null === $row->timestamp_played || !$this->topOfHourClock->isEnabled($station)) {
            return;
        }

        $start = CarbonImmutable::instance($row->timestamp_played);
        $boundary = $this->topOfHourClock->getNextBoundary($station, $start->toDateTimeImmutable());
        if ($this->topOfHourClock->clockWheelOwnsBoundary($station, $boundary)) {
            return;
        }

        $target = CarbonImmutable::instance(
            $this->topOfHourClock->getTargetStartFor($station, $start->toDateTimeImmutable())
        );
        $room = (float)$target->format('U.u') - (float)$start->format('U.u');
        if ($room <= 0.0) {
            // Starts after the ID: it opens the new hour.
            return;
        }

        $length = $this->airedLength->lengthOf($media);
        if ($length > $room + self::ID_CUT_GRACE_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                '"%s" runs %ds, but this slot starts at %s, only %ds before the %s Station ID, '
                . 'which would cut it. Pick something %ds or shorter.',
                $media->title ?? $media->text ?? 'This file',
                (int)round($length),
                $this->stationTime($station, $start),
                (int)floor($room),
                $this->stationTime($station, $target),
                (int)floor($room + self::ID_CUT_GRACE_SECONDS),
            ));
        }
    }

    private function stationTime(Station $station, ?DateTimeInterface $time): string
    {
        if (null === $time) {
            return 'its turn';
        }

        return CarbonImmutable::instance($time)->setTimezone($station->getTimezoneObject())->format('g:i:s A');
    }

    /** Swap play order with the neighbouring planned line. */
    private function move(Station $station, StationLogEntry $entry, bool $up): void
    {
        // Neighbour in air-time order, the order playout takes lines in.
        $neighbor = $this->em->createQuery(
            $up
                ? 'SELECT e FROM App\Entity\StationLogEntry e WHERE e.station = :station
                    AND e.status = :planned AND e.media IS NOT NULL
                    AND (e.planned_at < :at OR (e.planned_at = :at AND e.sequence < :seq))
                    ORDER BY e.planned_at DESC, e.sequence DESC'
                : 'SELECT e FROM App\Entity\StationLogEntry e WHERE e.station = :station
                    AND e.status = :planned AND e.media IS NOT NULL
                    AND (e.planned_at > :at OR (e.planned_at = :at AND e.sequence > :seq))
                    ORDER BY e.planned_at ASC, e.sequence ASC'
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('at', $entry->planned_at)
            ->setParameter('seq', $entry->sequence)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (!$neighbor instanceof StationLogEntry) {
            throw new InvalidArgumentException(
                $up ? 'This is already the next line to play.' : 'This is the last line in the log.'
            );
        }

        [$entry->sequence, $neighbor->sequence] = [$neighbor->sequence, $entry->sequence];
        [$entry->planned_at, $neighbor->planned_at] = [$neighbor->planned_at, $entry->planned_at];
        // A moved line stays where the operator put it.
        $entry->is_locked = true;
        $this->em->persist($neighbor);
    }

    /** Re-time the log in the background (extend only, never re-plan). */
    private function refresh(Station $station): void
    {
        $hours = $station->backend_config->linear_log_hours;
        $this->snapshotStore->markQueued($station, $hours);
        try {
            $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours, true, false));
        } catch (Throwable $e) {
            $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
        }
    }
}
