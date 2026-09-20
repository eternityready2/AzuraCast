<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event\GetSyncTasks;
use App\Sync\Task\StageTopOfHourStationIdTask;
use Plugin\TopOfHour\AiDjRuntimeConfiguration;
use Plugin\TopOfHour\FlexibleScheduleRuntimeConfiguration;
use Plugin\TopOfHour\RigidScheduleRuntimeConfiguration;
use Plugin\TopOfHour\TopOfHourQueueClockConstraint;
use Plugin\TopOfHour\TopOfHourRuntimeConfiguration;
use Plugin\TopOfHour\TopOfHourSongSwapSelector;

require_once __DIR__ . '/src/AiDjRuntimeConfiguration.php';
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
        // Keep AI DJ immediately before TOH at the same priority: strict is built
        // first (priority 16), then AI DJ, then TOH wraps both and stays authoritative.
        AiDjRuntimeConfiguration::class,
        TopOfHourRuntimeConfiguration::class,
        // Requirement 1: duration-matched swapping of the final song of the hour.
        // Subscribes to BuildQueue at priority -1, i.e. after the ordinary AutoDJ
        // selector has chosen a track and before the DMCA validator inspects it.
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
