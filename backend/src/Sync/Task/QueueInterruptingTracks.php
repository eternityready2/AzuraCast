<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\Queue;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\SponsorGuaranteedPlayoutService;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use DateTimeImmutable;
use Monolog\LogRecord;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

final class QueueInterruptingTracks extends AbstractTask
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Scheduler $scheduler,
        private readonly SponsorGuaranteedPlayoutService $sponsorGuarantee,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    /**
     * Process content that truly owns the interrupting queue.
     *
     * Scheduled playlists use the per-schedule strict_start flag as the
     * authoritative Flexible-vs-Strict signal. A stale playlist-level
     * "interrupt" option must not turn a Flexible scheduled programme into an
     * interrupting Smart Duck voiceover over normal rotation.
     */
    public function run(bool $force = false): void
    {
        // Queue building can flush/open its own transaction state. The normal
        // station batch iterator wraps processing in Doctrine transactions and
        // can leave nested savepoints invalid after those queue writes. Read IDs
        // first, then refetch one station at a time without the outer iterator
        // transaction.
        /** @var array<int, array{id: int|string}> $stationRows */
        $stationRows = $this->em->createQuery(
            <<<'DQL'
                SELECT s.id AS id FROM App\Entity\Station s
            DQL
        )->getScalarResult();

        foreach ($stationRows as $stationRow) {
            $this->em->clear();

            $station = $this->em->find(Station::class, (int)$stationRow['id']);
            if (!$station instanceof Station) {
                continue;
            }

            $this->logger->pushProcessor(
                function (LogRecord $record) use ($station) {
                    $record->extra['station'] = [
                        'id' => $station->id,
                        'name' => $station->name,
                    ];
                    return $record;
                }
            );

            try {
                $this->queueForStation($station);
            } catch (Throwable $e) {
                // A backend can legitimately be stopping/restarting between the
                // Supervisor status check and a telnet queue command. Never let that
                // transient race terminate the entire sync-task process.
                $this->logger->warning('Interrupting queue unavailable; will retry next minute.', [
                    'station_id' => $station->id,
                    'exception' => $e->getMessage(),
                ]);
            } finally {
                $this->logger->popProcessor();
            }
        }

        $this->em->clear();
    }

    private function queueForStation(Station $station): void
    {
        if (!$station->supportsAutoDjQueue()) {
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);
        if (!($backend instanceof Liquidsoap)) {
            return;
        }

        if (!$backend->isRunning($station)) {
            $this->logger->debug('Interrupting queue skipped because Liquidsoap is not running.');
            return;
        }

        $now = new DateTimeImmutable('now');
        $tz = $station->getTimezoneObject();
        $sponsorPlaylistIdsBehindPace = [];

        foreach ($this->sponsorGuarantee->getPlaylistsBehindPace($station, $now) as $sponsorPlaylist) {
            $sponsorPlaylistIdsBehindPace[$sponsorPlaylist->id] = true;
        }

        $hasInterruptingPlaylist = false;

        foreach ($station->playlists as $playlist) {
            $isSponsorBehindPace = isset($sponsorPlaylistIdsBehindPace[$playlist->id]);

            if ($playlist->schedule_items->count() > 0) {
                // Hard-interrupt (track_sensitive=false) for Strict-start schedules:
                // fires exactly at the scheduled minute to cut over immediately.
                if (
                    $this->scheduler->isPlaylistStrictStartDueNow($playlist, $tz, $now)
                    || $isSponsorBehindPace
                ) {
                    $hasInterruptingPlaylist = true;
                    break;
                }

                // Flexible scheduled playlists do NOT hard-interrupt mid-song, but
                // they still need to be pushed into the interrupting queue once we
                // are inside their active schedule window, otherwise the current
                // AutoDJ song finishes, Liquidsoap asks for next_song, and the
                // schedule_switch_playlists Liquidsoap switch (track_sensitive=true)
                // picks them up on its own. They only fail to start if the queue is
                // never populated - which is exactly what was happening before this
                // fix. Check isPlaylistScheduledToPlayNow to confirm we are within
                // the window, then let the normal interrupting-queue path below push
                // one song so Liquidsoap transitions at the next track boundary.
                if (
                    $playlist->is_enabled
                    && $this->scheduler->isPlaylistScheduledToPlayNow($playlist, $now)
                ) {
                    $hasInterruptingPlaylist = true;
                    break;
                }

                continue;
            }

            if ($playlist->isPlayable(true) || $isSponsorBehindPace) {
                $hasInterruptingPlaylist = true;
                break;
            }
        }

        if (!$hasInterruptingPlaylist) {
            return;
        }

        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting)) {
            $this->logger->info('Interrupting queue: Queue is not empty.');
            return;
        }

        $songsToPlay = $this->queue->getInterruptingQueue($station);
        if (empty($songsToPlay)) {
            return;
        }

        foreach ($songsToPlay as $sq) {
            $event = AnnotateNextSong::fromStationQueue($sq, true);
            $this->eventDispatcher->dispatch($event);

            $track = $event->buildAnnotations();
            $queueName = LiquidsoapQueues::Interrupting;

            $this->logger->debug('Submitting request to AutoDJ.', [
                'track' => $track,
                'queue' => $queueName->value,
            ]);
            $response = $backend->enqueue($station, $queueName, $track);
            $this->logger->debug('AutoDJ request response', ['response' => $response]);
        }
    }
}
