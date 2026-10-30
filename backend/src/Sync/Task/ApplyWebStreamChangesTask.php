<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Radio\Configuration;
use App\Radio\WebStreamAutoApply;
use App\Utilities\Time;
use Throwable;

/**
 * Applies saved web stream changes to the running station, so the operator
 * never has to press "Restart Broadcasting" for them. A restart is about two
 * seconds of silence, so it never runs in the top-of-hour window (:56-:07),
 * where it would break the Station ID; those changes wait until :08.
 */
final class ApplyWebStreamChangesTask extends AbstractTask
{
    public function __construct(
        private readonly WebStreamAutoApply $autoApply,
        private readonly Configuration $configuration,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->autoApply->getPending() as $stationId) {
            $station = $this->em->find(Station::class, $stationId);

            if (
                !$station instanceof Station
                || !$station->is_enabled
                || !$station->has_started
                || !$station->hasLocalServices()
            ) {
                // A station that is not running gets a fresh config when it starts.
                $this->autoApply->clear($stationId);
                continue;
            }

            $minute = (int)Time::nowInTimezone($station->getTimezoneObject())->format('i');
            if (!$force && ($minute >= 56 || $minute < 8)) {
                continue;
            }

            try {
                $this->configuration->writeConfiguration(
                    station: $station,
                    reloadSupervisor: true,
                    forceRestart: true
                );
                $this->autoApply->clear($stationId);

                $this->logger->info(
                    'Applied web stream changes to the running station.',
                    ['station' => $station->name]
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'Could not apply web stream changes; will retry next minute.',
                    ['station' => $station->name, 'exception' => $e->getMessage()]
                );
            }
        }
    }
}
