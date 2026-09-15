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
     * Process playlists that explicitly interrupt normal AutoDJ playback.
     *
     * Flexible/non-interrupting schedules are intentionally not skipped here;
     * they must be allowed to wait for the current track as configured.
     */
    public function run(bool $force = false): void
    {
        // Queue building can flush/open its own transaction state. The normal
        // ReadWriteBatchIteratorAggregate wraps each station in a Doctrine write
        // transaction/savepoint, which can conflict with nested queue persistence.
        // Read only station IDs here, then clear/refetch one station at a time so
        // there is no outer iterator transaction and the identity map stays bounded.
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

        $hasInterruptingPlaylist = false;
        $tz = $station->getTimezoneObject();

        foreach ($station->playlists as $playlist) {
            if (
                $playlist->isPlayable(true)
                || $this->scheduler->isPlaylistStrictStartDueNow($playlist, $tz)
            ) {
                $hasInterruptingPlaylist = true;
                break;
            }
        }

        if (!$hasInterruptingPlaylist && !empty($this->sponsorGuarantee->getPlaylistsBehindPace($station))) {
            $hasInterruptingPlaylist = true;
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
