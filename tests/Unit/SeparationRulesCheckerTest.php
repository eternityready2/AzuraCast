<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\StationMedia;
use App\Entity\StorageLocation;
use App\Radio\AutoDJ\ClockWheel\ClockWheelSeparationSettings;
use App\Radio\AutoDJ\ClockWheel\SeparationRulesChecker;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

final class SeparationRulesCheckerTest extends Unit
{
    private SeparationRulesChecker $checker;

    protected function _before(): void
    {
        $this->checker = new SeparationRulesChecker($this->createMock(LoggerInterface::class));
    }

    public function testDisabledReturnsAllCandidates(): void
    {
        $media = $this->makeMedia(1, 'Song A', 'Artist A');
        $settings = new ClockWheelSeparationSettings(enabled: false);

        $result = $this->checker->apply(
            [$media],
            $this->recentArtistPlay('Artist A', 5),
            $settings,
            new DateTimeImmutable('2026-05-31 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame([$media], $result->candidates);
        self::assertFalse($result->separationRelaxed);
    }

    public function testBlocksArtistWithinWindow(): void
    {
        $blocked = $this->makeMedia(1, 'Song A', 'Artist A');
        $allowed = $this->makeMedia(2, 'Song B', 'Artist B');
        $settings = new ClockWheelSeparationSettings(enabled: true, artistMinutes: 60, titleMinutes: 120);

        $result = $this->checker->apply(
            [$blocked, $allowed],
            $this->recentArtistPlay('Artist A', 10),
            $settings,
            new DateTimeImmutable('2026-05-31 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertCount(1, $result->candidates);
        self::assertSame(2, $result->candidates[0]->id);
    }

    public function testRelaxesWhenStrictFilterEmpty(): void
    {
        $only = $this->makeMedia(1, 'New Song', 'Blocked Artist');
        $settings = new ClockWheelSeparationSettings(enabled: true, artistMinutes: 60, titleMinutes: 60);

        $result = $this->checker->apply(
            [$only],
            $this->recentArtistPlay('Blocked Artist', 5),
            $settings,
            new DateTimeImmutable('2026-05-31 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertCount(1, $result->candidates);
        self::assertTrue($result->separationRelaxed);
    }

    public function testBurnRateDeprioritizesHotTracks(): void
    {
        $hot = $this->makeMedia(1, 'Hot', 'Artist');
        $hot->song_id = 'hot-song';
        $fresh = $this->makeMedia(2, 'Fresh', 'Artist');
        $fresh->song_id = 'fresh-song';

        $settings = new ClockWheelSeparationSettings(
            enabled: false,
            burnRateMaxPlays24h: 2,
        );

        $history = [
            [
                'song_id' => 'hot-song',
                'title' => 'Hot',
                'artist' => 'Artist',
                'timestamp_played' => time() - 3600,
            ],
            [
                'song_id' => 'hot-song',
                'title' => 'Hot',
                'artist' => 'Artist',
                'timestamp_played' => time() - 7200,
            ],
        ];

        $result = $this->checker->apply(
            [$hot, $fresh],
            $history,
            $settings,
            new DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        self::assertSame(2, $result->candidates[0]->id);
        self::assertTrue($result->burnRateWarning);
    }

    private function makeMedia(int $id, string $title, string $artist): StationMedia
    {
        $storage = new StorageLocation(StorageLocationTypes::StationMedia, StorageLocationAdapters::Local);
        $media = new StationMedia($storage, '/track_' . $id . '.mp3');
        $media->title = $title;
        $media->artist = $artist;
        $media->type = 'music';
        $media->length = 180.0;
        $media->mtime = time();
        $media->uploaded_at = time();
        $media->updateMetaFields();

        $ref = new \ReflectionProperty($media, 'id');
        $ref->setValue($media, $id);

        return $media;
    }

    /**
     * @return array<array{song_id:string, timestamp_played:int, title:string, artist:string}>
     */
    private function recentArtistPlay(string $artist, int $minutesAgo): array
    {
        return [
            [
                'song_id' => 'played',
                'title' => 'Other',
                'artist' => $artist,
                'timestamp_played' => time() - ($minutesAgo * 60),
            ],
        ];
    }

}
