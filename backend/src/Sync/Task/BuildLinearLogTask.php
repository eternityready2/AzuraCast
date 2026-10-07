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
        // Hourly, so run() can find the station's own local 3am (the pattern is
        // evaluated in UTC and cannot express a local hour across DST).
        return '7 * * * *';
    }

    /**
     * The log is built once a day, like an FM log. Between daily builds it only
     * changes by an operator's edit or a schedule change, each of which re-plans
     * on its own. The one other build is recovery: a station whose last build
     * failed, or that has never been built, gets another attempt every hour
     * until one succeeds, so a failed 3am build cannot leave it without a log.
     *
     * This used to rebuild whenever the log measured short of its horizon. The
     * measure counted the air running ahead of the plan as a hole, so it
     * rebuilt nearly every hour (2026-10-07), and every build re-planned the
     * lines behind each programme.
     */
    private function needsRecoveryBuild(Station $station): bool
    {
        $snapshot = $this->snapshotStore->get($station);

        return match ($snapshot['status'] ?? null) {
            'failed' => true,
            'ready' => empty($snapshot['entries']),
            // Queued or building: let it finish.
            'queued', 'building' => false,
            default => true,
        };
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

            if (!$force && !$isDailyBuild && !$this->needsRecoveryBuild($station)) {
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
