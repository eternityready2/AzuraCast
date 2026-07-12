<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\AutoDJ\TopOfHourIdScheduler;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Resolves which top-of-hour legal ID file plays next and writes it to a fixed path
 * for Liquidsoap's own timer to pick up.
 *
 * Runs automatically the moment "Require station ID at top of hour" is checked on the
 * Top of Hour settings page — no crontab entry, no console command to remember to
 * schedule, no separate deploy step. It's an ordinary AzuraCast Sync Task, polled every
 * ~250ms by Sync\RunnerCommand alongside everything else AzuraCast already runs on its
 * own (media checks, cleanup, etc).
 *
 * This task only does the part that genuinely needs Doctrine/DB access: picking the ID
 * file (duplicate-avoidance if there's more than one — a no-op today with a single
 * file), checking Clock Wheel/emergency conflicts, and recording the compliance
 * bookkeeping row that powers the "Top-of-hour ID compliance" stats on the settings
 * page. The time-critical part — the actual push, right before News — happens entirely
 * inside Liquidsoap via {@see \App\Radio\Backend\Liquidsoap\ConfigWriter::writeTopOfHourIdConfiguration},
 * with no PHP or telnet involved at the moment it fires.
 */
final class QueueTopOfHourId extends AbstractTask
{
    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly TopOfHourIdScheduler $topOfHourIdScheduler,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            try {
                $this->queueForStation($station);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Top-of-hour ID: failed for station "%s": %s', $station->name, $e->getMessage())
                );
            }
        }
    }

    private function queueForStation(Station $station): void
    {
        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return;
        }

        $now = new DateTimeImmutable('now');
        $tz = $station->getTimezoneObject();

        if (!$this->hourBoundaryPlanner->isTopOfHourIdDue($station, $now)) {
            return;
        }

        // Idempotency lock: the target hour bucket, not wall-clock "now", so an
        // overlapping tick within the same firing window can never re-resolve (and
        // re-record compliance bookkeeping for) a second ID for the same hour
        // boundary. The Liquidsoap-side cooldown ref guards the physical push
        // separately; this guards the DB/selection side.
        $targetHourStart = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $now);
        $lockKey = sprintf(
            'top_of_hour_id_lock_%d_%s',
            $station->id,
            CarbonImmutable::instance($targetHourStart)->setTimezone($tz)->format('YmdH')
        );

        if (null !== $this->cache->get($lockKey)) {
            $this->logger->debug('Top-of-hour ID: skipped, already resolved for this hour (lock held).');
            return;
        }
        // Held for 90 minutes: comfortably longer than any single hour, so it self-clears.
        $this->cache->set($lockKey, time(), 5400);

        // Delegates to the (BuildQueue-disabled) scheduler's shared resolveIfDue(),
        // which also re-checks isTopOfHourIdDue(), the emergency-schedule check, and
        // whether an active Clock Wheel already owns the :00 legal_id for this hour —
        // so this task can never conflict with the Clock Wheel system.
        $queueEntry = $this->topOfHourIdScheduler->resolveIfDue($station, $now);

        if (null === $queueEntry) {
            $this->logger->warning('Top-of-hour ID: could not resolve a mandatory legal_id track.');
            $this->cache->delete($lockKey);
            return;
        }

        $event = AnnotateNextSong::fromStationQueue($queueEntry, true);
        $this->eventDispatcher->dispatch($event);

        $nextIdPath = $station->getRadioTempDir() . '/top_of_hour_id_next.txt';

        if (false === @file_put_contents($nextIdPath, $event->buildAnnotations())) {
            $this->logger->error('Top-of-hour ID: failed to write next-id file.', ['path' => $nextIdPath]);
            $this->cache->delete($lockKey);
            return;
        }

        $this->logger->info('Top-of-hour ID: resolved and written for the Liquidsoap timer to pick up.', [
            'station_id' => $station->id,
            'path' => $nextIdPath,
        ]);
    }
}
