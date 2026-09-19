<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\AutoDJ\BroadcastClockPlanner;
use App\Radio\AutoDJ\BroadcastClockQueueTimingSubscriber;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;

final class BroadcastClockQueueTimingSubscriberTest extends Unit
{
    private BroadcastClockQueueTimingSubscriber $subscriber;

    protected function _inject(Module $testsModule): void
    {
        $planner = $testsModule->container->get(BroadcastClockPlanner::class);
        $this->subscriber = new BroadcastClockQueueTimingSubscriber($planner);
    }

    public function testRuntimeBoundaryCapPreservesFullMediaDurationForQueueApi(): void
    {
        $station = $this->makeStationWithUpcomingFlexibleProgram();

        $media = new StationMedia($station->media_storage_location, '/music.mp3');
        $media->title = 'No Greater Love';
        $media->artist = 'Test Artist';
        $media->type = 'music';
        $media->length = 203.0;
        $media->mtime = time();
        $media->uploaded_at = time();

        $queue = StationQueue::fromMedia($station, $media);
        $queue->duration = 203.0;

        $event = AnnotateNextSong::fromStationQueue($queue, true);
        $this->subscriber->applyRuntimeClockTarget($event);

        self::assertTrue($queue->hour_boundary_enforce_cap);
        self::assertNotNull($queue->hour_boundary_max_play_seconds);
        self::assertGreaterThan(0, $queue->hour_boundary_max_play_seconds);
        self::assertLessThan(203, $queue->hour_boundary_max_play_seconds);
        self::assertSame(
            203.0,
            $queue->duration,
            'Runtime playout caps must not replace the real queue/API media duration.',
        );
    }

    private function makeStationWithUpcomingFlexibleProgram(): Station
    {
        $station = new Station();
        $station->name = 'Queue Timing Test';
        $station->short_name = 'queue_timing_test';
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();
        $station->backend_config->crossfade = 0.0;

        // Schedule a Flexible program two wall-clock minutes ahead. The runtime
        // timing subscriber should create a cap shorter than the 203-second media
        // row while keeping StationQueue::duration unchanged for API/reporting.
        $anchor = CarbonImmutable::now(new DateTimeZone('UTC'))
            ->addMinutes(2)
            ->startOfMinute();
        $end = $anchor->addHour();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Upcoming Program';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = (int)$anchor->format('Hi');
        $schedule->end_time = (int)$end->format('Hi');
        $schedule->days = [];
        $schedule->strict_start = false;

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return $station;
    }
}
