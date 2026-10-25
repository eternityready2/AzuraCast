<?php

declare(strict_types=1);

namespace PureUnit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroup;
use Carbon\CarbonImmutable;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PlaylistGroupQueueTest extends TestCase
{
    private int $nextId = 1;

    public function testGroupWalkFindsASongPlaylistInsideANestedGroup(): void
    {
        $station = new Station();
        $outer = $this->group($station, 'Outer');
        $inner = $this->group($station, 'Inner');
        $quiet = $this->songs($station, 'Quiet');
        $cued = $this->songs($station, 'Cued');

        $this->addMember($outer, $quiet);
        $this->addMember($outer, $inner);
        $this->addMember($inner, $cued);

        $isCued = static fn(StationPlaylist $p): bool => $p === $cued;

        self::assertTrue(StationQueueRepository::anyGroupMember($outer, $isCued));
        self::assertTrue(StationQueueRepository::anyGroupMember($inner, $isCued));
    }

    public function testGroupWalkIsFalseWhenNoSongPlaylistMatches(): void
    {
        $station = new Station();
        $group = $this->group($station, 'Group');
        $this->addMember($group, $this->songs($station, 'A'));
        $this->addMember($group, $this->songs($station, 'B'));

        self::assertFalse(StationQueueRepository::anyGroupMember($group, static fn(): bool => false));
    }

    public function testGroupWalkOnlyChecksSongPlaylists(): void
    {
        $station = new Station();
        $group = $this->group($station, 'Group');
        $remote = $this->songs($station, 'Remote');
        $remote->source = PlaylistSources::RemoteUrl;
        $this->addMember($group, $remote);

        $checked = [];
        StationQueueRepository::anyGroupMember(
            $group,
            static function (StationPlaylist $p) use (&$checked): bool {
                $checked[] = $p->name;
                return true;
            }
        );

        self::assertSame([], $checked);
    }

    public function testGroupWalkStopsOnCircularGroups(): void
    {
        $station = new Station();
        $a = $this->group($station, 'A');
        $b = $this->group($station, 'B');
        $this->addMember($a, $b);
        $this->addMember($b, $a);

        self::assertFalse(StationQueueRepository::anyGroupMember($a, static fn(): bool => true));
    }

    public function testRestartResetsGroupsButKeepsTheirLastResetTime(): void
    {
        $station = new Station();
        // Random order: the reset needs no bulk queries, only the timestamp.
        $group = $this->group($station, 'Group');
        $group->order = PlaylistOrders::Random;
        $songs = $this->songs($station, 'Songs');
        $songs->queue_reset_at = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC');
        $station->playlists->add($group);
        $station->playlists->add($songs);

        $lastReset = CarbonImmutable::parse('2026-10-06 02:00:00', 'UTC');
        $group->queue_reset_at = $lastReset;

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getIdentityMap')->willReturn([]);

        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);
        $em->expects(self::atLeastOnce())->method('flush');

        $repo = new StationPlaylistRepository();
        $repo->setEntityManager($em);

        $repo->resetAllPlaylistGroupQueues($station);

        self::assertEquals($lastReset, $group->queue_reset_at);
        // Song playlists are left to StationPlaylistMediaRepository::resetAllQueues().
        self::assertEquals(CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'), $songs->queue_reset_at);
    }

    private function group(Station $station, string $name): StationPlaylist
    {
        $playlist = $this->songs($station, $name);
        $playlist->source = PlaylistSources::Playlists;
        return $playlist;
    }

    private function songs(Station $station, string $name): StationPlaylist
    {
        $playlist = new StationPlaylist($station);
        $playlist->name = $name;
        (new ReflectionProperty(StationPlaylist::class, 'id'))->setValue($playlist, $this->nextId++);
        return $playlist;
    }

    private function addMember(StationPlaylist $group, StationPlaylist $member): void
    {
        $group->playlists->add(new StationPlaylistGroup($member, $group));
    }
}
