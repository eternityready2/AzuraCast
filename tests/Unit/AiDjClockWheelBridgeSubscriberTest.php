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
        /** @var array{0: array{0: string, 1: int}, 1: array{0: string, 1: int}} $queueEvents */
        $queueEvents = QueueBuilder::getSubscribedEvents()[BuildQueue::class];

        /** @var array{0: string, 1: int} $bridgeEvent */
        $bridgeEvent = AiDjClockWheelBridgeSubscriber::getSubscribedEvents()[BuildQueue::class];

        /** @var array{0: array{0: string, 1: int}} $clockWheelEvents */
        $clockWheelEvents = ClockWheelScheduler::getSubscribedEvents()[BuildQueue::class];

        $requestPriority = $queueEvents[0][1];
        $bridgePriority = $bridgeEvent[1];
        $clockWheelPriority = $clockWheelEvents[0][1];
        $normalQueuePriority = $queueEvents[1][1];

        self::assertGreaterThan($bridgePriority, $requestPriority);
        self::assertGreaterThan($clockWheelPriority, $bridgePriority);
        self::assertGreaterThan($normalQueuePriority, $clockWheelPriority);
    }

    public function testBridgeExistsBecauseNativeAiDjSubscribersRunAfterClockWheel(): void
    {
        /** @var array{0: array{0: string, 1: int}} $clockWheelEvents */
        $clockWheelEvents = ClockWheelScheduler::getSubscribedEvents()[BuildQueue::class];

        /** @var array{0: string, 1: int} $lifecycleEvent */
        $lifecycleEvent = AiDjShiftLifecycleListener::getSubscribedEvents()[BuildQueue::class];

        /** @var array{0: string, 1: int} $talkEvent */
        $talkEvent = AiDjQueueListener::getSubscribedEvents()[BuildQueue::class];

        $clockWheelPriority = $clockWheelEvents[0][1];
        $lifecyclePriority = $lifecycleEvent[1];
        $talkPriority = $talkEvent[1];

        self::assertGreaterThan($lifecyclePriority, $clockWheelPriority);
        self::assertGreaterThan($talkPriority, $clockWheelPriority);
    }
}
