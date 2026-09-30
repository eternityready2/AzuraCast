<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
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
        // Hourly. run() does the full FM-style daily build at the station's own
        // local 3am (checked here rather than in the pattern, which is evaluated
        // in UTC and so cannot express a local hour across DST), and otherwise
        // only tops the log up when it has decayed below the requested horizon.
        return '7 * * * *';
    }

    /**
     * The configured hours are a rolling minimum, not a per-build target: a log
     * built only at 3am covers less and less as the day airs out of it, so by
     * evening the page legitimately shows well under 24 hours. Top the log up
     * on any hourly pass where coverage from *now* has fallen below what the
     * station asked for.
     */
    private function isShortOfHorizon(Station $station): bool
    {
        $snapshot = $this->snapshotStore->get($station);
        if (!in_array($snapshot['status'] ?? null, ['ready', 'failed'], true)) {
            // A build is already queued or running; let it finish.
            return false;
        }

        $hours = max(1, min(48, $station->backend_config->linear_log_hours));

        return ((int)($snapshot['coverage_end'] ?? 0)) - time() < $hours * 3600;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$force && !$station->backend_config->linear_log_enabled) {
                continue;
            }

            if (!$station->supportsAutoDjQueue()) {
                continue;
            }

            $isDailyBuild = self::BUILD_LOCAL_HOUR
                === (int)Time::nowInTimezone($station->getTimezoneObject())->format('G');

            if (!$force && !$isDailyBuild && !$this->isShortOfHorizon($station)) {
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
