<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\RigidScheduleWindowResolver;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

final class RigidScheduleWindowResolverTest extends Unit
{
    public function testStrictHymnsWindowIsActiveFromMidnightUntilSixPm(): void
    {
        [$station] = $this->makePlaylist(true);
        $resolver = new RigidScheduleWindowResolver();

        $active = $resolver->getActiveWindow(
            $station,
            new DateTimeImmutable('2026-09-12 12:00:00', new DateTimeZone('UTC')),
        );

        self::assertNotNull($active);
        self::assertSame('Hymns and Favorites - Music', $active['playlist']->name);
        self::assertSame('2026-09-12 00:00:00', $active['start']->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-12 18:00:00', $active['end']->format('Y-m-d H:i:s'));
    }

    public function testExactEndBoundaryReleasesRigidAuthority(): void
    {
        [$station] = $this->makePlaylist(true);
        $resolver = new RigidScheduleWindowResolver();

        self::assertNull($resolver->getActiveWindow(
            $station,
            new DateTimeImmutable('2026-09-12 18:00:00', new DateTimeZone('UTC')),
        ));
    }

    public function testOrdinaryFlexibleRotationIsNotReportedAsRigid(): void
    {
        [$station] = $this->makePlaylist(false);
        $resolver = new RigidScheduleWindowResolver();

        self::assertNull($resolver->getActiveWindow(
            $station,
            new DateTimeImmutable('2026-09-12 12:00:00', new DateTimeZone('UTC')),
        ));
    }

    public function testPlaylistProgrammeChoiceDoesNotTurnFlexibleRowIntoStrict(): void
    {
        [$station, $playlist] = $this->makePlaylist(false);
        $playlist->backend_options = [StationPlaylist::OPTION_INTERRUPT_OTHER_SONGS];
        $resolver = new RigidScheduleWindowResolver();

        self::assertNull(
            $resolver->getActiveWindow(
                $station,
                new DateTimeImmutable('2026-09-12 12:00:00', new DateTimeZone('UTC')),
            ),
            'Playlist-wide Programme behavior must not silently convert a Flexible schedule row into Strict.',
        );
    }

    public function testEmergencyRowStillGetsRigidAuthority(): void
    {
        [$station, , $schedule] = $this->makePlaylist(false);
        $schedule->is_emergency = true;
        $resolver = new RigidScheduleWindowResolver();

        self::assertNotNull($resolver->getActiveWindow(
            $station,
            new DateTimeImmutable('2026-09-12 12:00:00', new DateTimeZone('UTC')),
        ));
    }

    /** @return array{Station, StationPlaylist, StationSchedule} */
    private function makePlaylist(bool $strict): array
    {
        $station = new Station();
        $station->name = 'Reporting Window Test';
        $station->short_name = 'reporting_window_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/reporting_window_test';

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Hymns and Favorites - Music';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 0;
        $schedule->end_time = 1800;
        $schedule->days = [];
        $schedule->strict_start = $strict;

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return [$station, $playlist, $schedule];
    }
}
