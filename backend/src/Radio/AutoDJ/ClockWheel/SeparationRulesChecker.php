<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\StationMedia;
use App\Radio\AutoDJ\DuplicatePrevention;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Time-window artist/title separation and optional 24h burn-rate deprioritization (PR9).
 */
final class SeparationRulesChecker
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    public function apply(
        array $candidates,
        array $recentHistory,
        ClockWheelSeparationSettings $settings,
        DateTimeImmutable $expectedPlayTime,
    ): SeparationFilterResult {
        if ($candidates === []) {
            return new SeparationFilterResult($candidates);
        }

        $burnResult = $this->deprioritizeBurnRate($candidates, $recentHistory, $settings, $expectedPlayTime);
        $candidates = $burnResult['candidates'];

        if (!$settings->enabled) {
            return new SeparationFilterResult(
                $candidates,
                burnRateWarning: $burnResult['warning'],
            );
        }

        $strict = $this->filterByArtistAndTitle(
            $burnResult['candidates'],
            $recentHistory,
            $settings,
            $expectedPlayTime,
            enforceArtist: true,
            enforceTitle: true,
        );

        if ($strict !== []) {
            return new SeparationFilterResult(
                $strict,
                burnRateWarning: $burnResult['warning'],
            );
        }

        $this->logger->info('Clock Wheel: relaxing title separation window.');

        $titleRelaxed = $this->filterByArtistAndTitle(
            $burnResult['candidates'],
            $recentHistory,
            $settings,
            $expectedPlayTime,
            enforceArtist: true,
            enforceTitle: false,
        );

        if ($titleRelaxed !== []) {
            return new SeparationFilterResult(
                $titleRelaxed,
                separationRelaxed: true,
                burnRateWarning: $burnResult['warning'],
            );
        }

        $this->logger->warning('Clock Wheel: separation fully relaxed; using burn-adjusted candidate order.');

        return new SeparationFilterResult(
            $burnResult['candidates'],
            separationRelaxed: true,
            burnRateWarning: $burnResult['warning'],
        );
    }

    /**
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return array{candidates: StationMedia[], warning: bool}
     */
    private function deprioritizeBurnRate(
        array $candidates,
        array $recentHistory,
        ClockWheelSeparationSettings $settings,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        $max = $settings->burnRateMaxPlays24h;
        if ($max === null || $max < 1) {
            return ['candidates' => $candidates, 'warning' => false];
        }

        $threshold = $expectedPlayTime->getTimestamp() - (24 * 60 * 60);
        $playCounts = [];

        foreach ($recentHistory as $row) {
            $ts = $this->timestampToUnix($row['timestamp_played'] ?? 0);
            if ($ts < $threshold) {
                continue;
            }

            $songId = $row['song_id'];
            $playCounts[$songId] = ($playCounts[$songId] ?? 0) + 1;
        }

        $preferred = [];
        $burned = [];

        foreach ($candidates as $media) {
            $count = $playCounts[$media->song_id] ?? 0;
            if ($count >= $max) {
                $burned[] = $media;
            } else {
                $preferred[] = $media;
            }
        }

        if ($preferred === []) {
            return ['candidates' => $candidates, 'warning' => true];
        }

        return [
            'candidates' => [...$preferred, ...$burned],
            'warning' => $burned !== [],
        ];
    }

    /**
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return StationMedia[]
     */
    private function filterByArtistAndTitle(
        array $candidates,
        array $recentHistory,
        ClockWheelSeparationSettings $settings,
        DateTimeImmutable $expectedPlayTime,
        bool $enforceArtist,
        bool $enforceTitle,
    ): array {
        $artistHistory = $enforceArtist
            ? $this->filterHistoryByMinutes($recentHistory, $settings->artistMinutes, $expectedPlayTime)
            : [];
        $titleHistory = $enforceTitle
            ? $this->filterHistoryByMinutes($recentHistory, $settings->titleMinutes, $expectedPlayTime)
            : [];

        $blockedArtists = $this->collectArtistKeys($artistHistory);
        $blockedTitles = $this->collectTitleKeys($titleHistory);

        $filtered = [];

        foreach ($candidates as $media) {
            if ($enforceTitle && isset($blockedTitles[$this->normalize($media->title)])) {
                continue;
            }

            if ($enforceArtist && $this->artistOverlaps($media->artist, $blockedArtists)) {
                continue;
            }

            $filtered[] = $media;
        }

        return $filtered;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $history
     *
     * @return array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}>
     */
    private function filterHistoryByMinutes(
        array $history,
        int $minutes,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        $threshold = $expectedPlayTime->getTimestamp() - ($minutes * 60);

        return array_values(array_filter(
            $history,
            fn (array $row): bool => $this->timestampToUnix($row['timestamp_played'] ?? 0) >= $threshold
        ));
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $history
     *
     * @return array<string, string>
     */
    private function collectTitleKeys(array $history): array
    {
        $titles = [];
        foreach ($history as $row) {
            $key = $this->normalize($row['title'] ?? '');
            if ($key !== '') {
                $titles[$key] = $key;
            }
        }

        return $titles;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $history
     *
     * @return array<string, string>
     */
    private function collectArtistKeys(array $history): array
    {
        $artists = [];
        foreach ($history as $row) {
            foreach ($this->splitArtists($row['artist'] ?? '') as $part) {
                $artists[$part] = $part;
            }
        }

        return $artists;
    }

    /**
     * @param array<string, string> $blockedArtists
     */
    private function artistOverlaps(?string $artist, array $blockedArtists): bool
    {
        foreach ($this->splitArtists($artist) as $part) {
            if (isset($blockedArtists[$part])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function splitArtists(?string $artist): array
    {
        $divider = chr(7);
        $normalized = str_replace(DuplicatePrevention::ARTIST_SEPARATORS, $divider, trim($artist ?? ''));
        $parts = explode($divider, $normalized);

        return array_values(array_filter(
            array_map(fn (string $p): string => $this->normalize($p), $parts),
            static fn (string $p): bool => $p !== ''
        ));
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim($value ?? ''));
    }

    private function timestampToUnix(mixed $timestamp): int
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->getTimestamp();
        }

        return (int)$timestamp;
    }
}
