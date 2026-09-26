<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Service\StationDiagnostics;
use App\Utilities\Time;
use Monolog\LogRecord;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

final class BuildLinearLogTask extends AbstractTask
{
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    /** Station-local hour of the daily build (FM-style early-morning log). */
    private const int BUILD_LOCAL_HOUR = 3;

    public static function getSchedulePattern(): string
    {
        // Once a day, like FM traffic/music logs: built early morning through
        // the end of the next day. Live timing is re-computed on the page.
        // Sync cron patterns are evaluated in UTC, so fire at :07 every hour and
        // let run() keep only the station's own local 3am (DST included).
        return '7 * * * *';
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$force && !$station->backend_config->linear_log_enabled) {
                continue;
            }

            if (
                !$force
                && self::BUILD_LOCAL_HOUR !== (int)Time::nowInTimezone($station->getTimezoneObject())->format('G')
            ) {
                continue;
            }

            if (!$station->supportsAutoDjQueue()) {
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

            $hours = $station->backend_config->linear_log_hours;

            try {
                $this->snapshotStore->markQueued($station, $hours);
                $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours, $force, false, true));
            } catch (Throwable $e) {
                $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
                $this->logger->error(
                    'Unable to queue Linear Log build: ' . $e->getMessage(),
                    ['exception' => $e]
                );
                $this->diagnostics->error(
                    $station,
                    'Linear Log',
                    'Unable to queue Linear Log build.',
                    [
                        'hours' => $hours,
                        'error' => $e->getMessage(),
                    ]
                );
            } finally {
                $this->logger->popProcessor();
            }
        }
    }
}
