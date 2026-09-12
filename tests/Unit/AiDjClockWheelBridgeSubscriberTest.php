<?php

declare(strict_types=1);

namespace Unit;

use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\AiDjClockWheelBridgeSubscriber;
use App\Radio\AutoDJ\AiDjQueueListener;
use App\Radio\AutoDJ\AiDjShiftLifecycleListener;
use App\Radio\AutoDJ\ClockWheelScheduler;
use App\Radio\AutoDJ\QueueBuilder;
use Codeception\Test\Unit;

final class AiDjClockWheelBridgeSubscriberTest extends Unit
{
    public function testBridgeRunsBetweenListenerRequestsAndClockWheelSelection(): void
    {
        $requestPriority = QueueBuilder::getSubscribedEvents()[BuildQueue::class][0][1];
        $bridgePriority = AiDjClockWheelBridgeSubscriber::getSubscribedEvents()[BuildQueue::class][1];
        $clockWheelPriority = ClockWheelScheduler::getSubscribedEvents()[BuildQueue::class][0][1];
        $normalQueuePriority = QueueBuilder::getSubscribedEvents()[BuildQueue::class][1][1];

        self::assertGreaterThan($bridgePriority, $requestPriority);
        self::assertGreaterThan($clockWheelPriority, $bridgePriority);
        self::assertGreaterThan($normalQueuePriority, $clockWheelPriority);
    }

    public function testBridgeExistsBecauseNativeAiDjSubscribersRunAfterClockWheel(): void
    {
        $clockWheelPriority = ClockWheelScheduler::getSubscribedEvents()[BuildQueue::class][0][1];
        $lifecyclePriority = AiDjShiftLifecycleListener::getSubscribedEvents()[BuildQueue::class][1];
        $talkPriority = AiDjQueueListener::getSubscribedEvents()[BuildQueue::class][1];

        self::assertGreaterThan($lifecyclePriority, $clockWheelPriority);
        self::assertGreaterThan($talkPriority, $clockWheelPriority);
    }
}
