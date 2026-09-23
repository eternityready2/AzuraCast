<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event\GetSyncTasks;
use App\Sync\Task\StageTopOfHourStationIdTask;
use Plugin\TopOfHour\FlexibleScheduleRuntimeConfiguration;
use Plugin\TopOfHour\RigidScheduleRuntimeConfiguration;
use Plugin\TopOfHour\TopOfHourQueueClockConstraint;
use Plugin\TopOfHour\TopOfHourRuntimeConfiguration;
use Plugin\TopOfHour\TopOfHourSongSwapSelector;

require_once __DIR__ . '/src/FlexibleScheduleRuntimeConfiguration.php';
require_once __DIR__ . '/src/RigidScheduleRuntimeConfiguration.php';
require_once __DIR__ . '/src/TopOfHourQueueClockConstraint.php';
require_once __DIR__ . '/src/TopOfHourRuntimeConfiguration.php';
require_once __DIR__ . '/src/TopOfHourSongSwapSelector.php';

return static function (CallableEventDispatcherInterface $dispatcher): void {
    $dispatcher->addServiceSubscriber([
        FlexibleScheduleRuntimeConfiguration::class,
        RigidScheduleRuntimeConfiguration::class,
        TopOfHourQueueClockConstraint::class,
        // AiDjRuntimeConfiguration (dedicated ai_dj lane) is deliberately not
        // registered: its fallback never selected queued speech, so nothing aired.
        // Without it, Liquidsoap::enqueue() routes speech via the Requests queue.
        TopOfHourRuntimeConfiguration::class,
        // Duration-matched swapping of the final song of the hour. Subscribes to
        // BuildQueue at priority -1: after the ordinary AutoDJ selector makes its
        // pick, before the DMCA validator checks it.
        TopOfHourSongSwapSelector::class,
    ]);

    // Staging is operationally owned by the plugin as well. Removing or disabling
    // the plugin therefore removes both the absolute wall-clock runtime lane and
    // its pre-staging task without touching unrelated AutoDJ behavior.
    $dispatcher->addListener(
        GetSyncTasks::class,
        static function (GetSyncTasks $event): void {
            $event->addTasks([
                StageTopOfHourStationIdTask::class,
            ]);
        }
    );
};
