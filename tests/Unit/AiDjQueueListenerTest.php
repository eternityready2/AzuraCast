<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\AiDj;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Radio\AutoDJ\AiDjQueueListener;
use App\Service\AiDjGenerator;
use App\Service\AiDjScheduler;
use App\Tests\Module;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\MockObject;

final class AiDjQueueListenerTest extends Unit
{
    private AiDjQueueListener $listener;

    private AiDjScheduler&MockObject $scheduler;

    private AiDjGenerator&MockObject $generator;

    private Adapters&MockObject $adapters;

    protected function _inject(Module $testsModule): void
    {
        $this->scheduler = $this->createMock(AiDjScheduler::class);
        $this->generator = $this->createMock(AiDjGenerator::class);
        $this->adapters = $this->createMock(Adapters::class);

        $em = $testsModule->em;

        $this->listener = new AiDjQueueListener(
            $this->scheduler,
            $this->generator,
            $this->adapters,
            $em,
        );
    }

    /**
     * Injection skipped when no active DJ exists for time slot.
     */
    public function testSkipsInjectionWhenNoActiveDj(): void
    {
        $station = new Station();
        $station->name = 'Test Station';

        $event = $this->createMockBuildQueueEvent($station);

        $this->scheduler
            ->expects(self::once())
            ->method('findActiveDj')
            ->willReturn(null);

        $this->generator
            ->expects(self::never())
            ->method('generateSongIntro');

        $this->adapters
            ->expects(self::never())
            ->method('getBackendAdapter');

        $this->listener->onBuildQueue($event);
    }

    /**
     * Injection skipped when DJ exists but clip generation fails (null return).
     * Fail-open: error logged, playback continues normally.
     */
    public function testSkipsInjectionWhenClipGenerationFails(): void
    {
        $station = new Station();
        $station->name = 'Test Station';

        $dj = new AiDj($station);
        $dj->setName('Test DJ');

        $event = $this->createMockBuildQueueEvent($station);

        $this->scheduler
            ->expects(self::once())
            ->method('findActiveDj')
            ->willReturn($dj);

        $backend = $this->createMock(Liquidsoap::class);
        $backend
            ->expects(self::once())
            ->method('isQueueEmpty')
            ->with($station, LiquidsoapQueues::Interrupting)
            ->willReturn(true);

        $this->adapters
            ->expects(self::once())
            ->method('getBackendAdapter')
            ->with($station)
            ->willReturn($backend);

        $this->generator
            ->expects(self::once())
            ->method('generateSongIntro')
            ->willReturn(null);

        $backend
            ->expects(self::never())
            ->method('enqueue');

        $this->listener->onBuildQueue($event);
    }

    /**
     * Create a mock BuildQueue event with minimal setup.
     */
    private function createMockBuildQueueEvent(Station $station): BuildQueue
    {
        $event = $this->createMock(BuildQueue::class);
        $event
            ->expects(self::any())
            ->method('getStation')
            ->willReturn($station);

        $event
            ->expects(self::any())
            ->method('isInterrupting')
            ->willReturn(false);

        $song = Song::createFromText('Artist - Title');
        $song->artist = 'Artist';
        $song->title = 'Title';

        $queueEntry = new StationQueue($station, $song);
        $event
            ->expects(self::any())
            ->method('getNextSongs')
            ->willReturn([$queueEntry]);

        $event
            ->expects(self::any())
            ->method('getExpectedPlayTime')
            ->willReturn(time());

        return $event;
    }
}
