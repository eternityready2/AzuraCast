<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\Scheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;
use UnitTester;

final class PlaylistPlayoutBehaviorTest extends Unit
{
    protected UnitTester $tester;

    private Scheduler $scheduler;

    protected function _inject(Module $testsModule): void
    {
        $this->scheduler = $testsModule->container->get(Scheduler::class);
    }

    public function testBackendOptionFlagsMapToPlayoutHelpers(): void
    {
        $playlist = new StationPlaylist($this->makeStation());
        $playlist->backend_options = [
            StationPlaylist::OPTION_INTERRUPT_OTHER_SONGS,
            StationPlaylist::OPTION_PRIORITIZE_OVER_REQUESTS,
            StationPlaylist::OPTION_ALLOW_OVERRUN,
            StationPlaylist::OPTION_PLAY_SINGLE_TRACK,
            StationPlaylist::OPTION_MERGE,
        ];

        self::assertTrue($playlist->backendInterruptOtherSongs());
        self::assertTrue($playlist->backendPrioritizeOverRequests());
        self::assertTrue($playlist->backendAllowOverrun());
        self::assertTrue($playlist->backendPlaySingleTrack());
        self::assertTrue($playlist->backendMerge());
    }

    public function testFutureDatedScheduleCannotPlayEarly(): void
    {
        $station = $this->makeStation();
        $playlist = new StationPlaylist($station);
        $playlist->name = 'Future Saturday Programme';

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1200;
        $schedule->end_time = 1500;
        $schedule->start_date = '2026-09-12';
        $schedule->end_date = '2027-12-31';
        $schedule->days = [6];
        $playlist->schedule_items->add($schedule);

        $utc = new DateTimeZone('UTC');

        // Previous Saturday: same weekday/time, but before the configured start date.
        self::assertFalse(
            $this->scheduler->shouldPlaylistPlayNow(
                $playlist,
                CarbonImmutable::create(2026, 9, 5, 13, 0, 0, $utc)
            )
        );

        // Thursday before the start date must also remain ineligible.
        self::assertFalse(
            $this->scheduler->shouldPlaylistPlayNow(
                $playlist,
                CarbonImmutable::create(2026, 9, 10, 13, 0, 0, $utc)
            )
        );

        // First configured Saturday inside the window is eligible.
        self::assertTrue(
            $this->scheduler->shouldPlaylistPlayNow(
                $playlist,
                CarbonImmutable::create(2026, 9, 12, 13, 0, 0, $utc)
            )
        );
    }

    public function testStrictStartOnlyTriggersAtValidExactStartMinute(): void
    {
        $station = $this->makeStation();
        $playlist = new StationPlaylist($station);
        $playlist->name = 'Strict Saturday Programme';

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1200;
        $schedule->end_time = 1500;
        $schedule->start_date = '2026-09-12';
        $schedule->end_date = '2027-12-31';
        $schedule->days = [6];
        $schedule->strict_start = true;
        $playlist->schedule_items->add($schedule);

        $utc = new DateTimeZone('UTC');

        self::assertFalse(
            $this->scheduler->isPlaylistStrictStartDueNow(
                $playlist,
                $utc,
                CarbonImmutable::create(2026, 9, 5, 12, 0, 0, $utc)
            )
        );

        self::assertTrue(
            $this->scheduler->isPlaylistStrictStartDueNow(
                $playlist,
                $utc,
                CarbonImmutable::create(2026, 9, 12, 12, 0, 0, $utc)
            )
        );

        self::assertFalse(
            $this->scheduler->isPlaylistStrictStartDueNow(
                $playlist,
                $utc,
                CarbonImmutable::create(2026, 9, 12, 12, 1, 0, $utc)
            )
        );
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'Playlist Playout Test Station';
        $station->short_name = 'playlist_playout_test';
        $station->timezone = 'UTC';

        return $station;
    }
}
