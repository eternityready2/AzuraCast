<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StorageLocation;
use App\Radio\AutoDJ\ClockWheel\HourTimeline;
use App\Radio\AutoDJ\ClockWheel\TimelinePlan;
use App\Radio\AutoDJ\ClockWheelScheduler;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Radio\AutoDJ\ScheduleConflictChecker;
use Codeception\Test\Unit;
use Mockery;
use Monolog\Logger;
use ReflectionMethod;

class ClockWheelSchedulerWindowTest extends Unit
{
    private ClockWheelScheduler $scheduler;

    private StorageLocation $storage;

    private StationClockWheelSlot $slot;

    protected function _before(): void
    {
        $this->scheduler = new ClockWheelScheduler(
            Mockery::mock(StationQueueRepository::class),
            Mockery::mock(DuplicatePrevention::class),
            Mockery::mock(ScheduleConflictChecker::class),
            new HourTimeline()
        );
        $this->scheduler->setLogger(new Logger('test'));
        $this->scheduler->setEntityManager(Mockery::mock(ReloadableEntityManagerInterface::class));

        $this->storage = new StorageLocation(
            StorageLocationTypes::StationMedia,
            StorageLocationAdapters::Local
        );

        /** @var Station $station */
        $station = Mockery::mock(Station::class);
        $wheel = new StationClockWheel($station);
        $this->slot = new StationClockWheelSlot($wheel);
        $this->slot->position_seconds = 0;
    }

    public function testKeepsOnlyTracksThatFitTheAvailableWindow(): void
    {
        $short  = $this->buildMedia(120.0, 'short.mp3');
        $medium = $this->buildMedia(240.0, 'medium.mp3');
        $long   = $this->buildMedia(900.0, 'long.mp3');

        $kept = $this->invokeFilter([$short, $medium, $long], 300);

        self::assertCount(2, $kept);
        self::assertContains($short, $kept);
        self::assertContains($medium, $kept);
        self::assertNotContains($long, $kept);
    }

    public function testHonoursFiveSecondBufferOnAvailableWindow(): void
    {
        $exact     = $this->buildMedia(305.0, 'exact-with-buffer.mp3');
        $oneOver   = $this->buildMedia(306.0, 'one-second-over.mp3');

        $kept = $this->invokeFilter([$exact, $oneOver], 300);

        self::assertContains(
            $exact,
            $kept,
            'A 305s track must fit a 300s window with the 5s buffer applied.'
        );
        self::assertNotContains(
            $oneOver,
            $kept,
            'A 306s track must exceed the 300s + 5s buffer and be dropped.'
        );
    }

    public function testRespectsSlotDurationCapWhenLowerThanAvailableSeconds(): void
    {
        $this->slot->duration_seconds = 60;

        $short = $this->buildMedia(45.0, 'short.mp3');
        $long  = $this->buildMedia(120.0, 'long.mp3');

        $kept = $this->invokeFilter([$short, $long], 600);

        self::assertSame(
            [$short],
            array_values($kept),
            'When the slot duration cap (60s) is tighter than the available window (600s), the cap must win.'
        );
    }

    public function testFallsBackToShortestTrackWhenNothingFits(): void
    {
        $shortest = $this->buildMedia(400.0, 'shortest.mp3');
        $longer   = $this->buildMedia(500.0, 'longer.mp3');
        $longest  = $this->buildMedia(900.0, 'longest.mp3');

        $kept = $this->invokeFilter([$longer, $longest, $shortest], 60);

        self::assertCount(1, $kept);
        self::assertSame($shortest, $kept[0]);
    }

    public function testDropsCandidatesWithZeroOrNegativeLength(): void
    {
        $valid    = $this->buildMedia(180.0, 'valid.mp3');
        $zero     = $this->buildMedia(0.0,   'zero.mp3');
        $negative = $this->buildMedia(-5.0,  'negative.mp3');

        $kept = $this->invokeFilter([$valid, $zero, $negative], 600);

        self::assertSame([$valid], array_values($kept));
    }

    /**
     * @param StationMedia[] $candidates
     * @return StationMedia[]
     */
    private function invokeFilter(array $candidates, int $availableSeconds): array
    {
        $method = new ReflectionMethod($this->scheduler, 'filterToWindow');
        return $method->invoke($this->scheduler, $candidates, $this->slot, $availableSeconds);
    }

    private function buildMedia(float $length, string $path): StationMedia
    {
        $media = new StationMedia($this->storage, $path);
        $media->length = $length;
        return $media;
    }
}
