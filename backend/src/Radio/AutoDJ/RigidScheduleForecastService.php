<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Exception;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use JsonException;

/**
 * Forecasts the exact song order owned by the dedicated rigid Liquidsoap source.
 *
 * Rigid scheduled programmes intentionally bypass the ordinary PHP AutoDJ queue.
 * That keeps wall-clock playback safe, but it also means the ordinary queue cannot
 * be used for Up Next, Upcoming Queue or the Linear Log while a rigid programme is
 * active. The runtime therefore exposes its playlist cursor through a local server
 * command; this service consumes that cursor and turns it back into StationMedia
 * rows for all user-facing forecast surfaces.
 */
final class RigidScheduleForecastService
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly RigidScheduleWindowResolver $windowResolver,
        private readonly Liquidsoap $liquidsoap,
    ) {
    }

    /** @return list<RigidScheduleForecastItem> */
    public function getActiveForecast(
        Station $station,
        ?DateTimeImmutable $at = null,
        int $limit = 250,
    ): array {
        $at ??= Time::nowUtc();
        $window = $this->windowResolver->getActiveWindow($station, $at);
        if (null === $window) {
            return [];
        }

        return $this->getForecast($station, $at, $window['end'], $limit);
    }

    /** @return list<RigidScheduleForecastItem> */
    public function getForecast(
        Station $station,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        int $limit = 1000,
    ): array {
        if ($rangeEnd <= $rangeStart || $limit < 1) {
            return [];
        }

        $windows = $this->windowResolver->getWindows($station, $rangeStart, $rangeEnd);
        if ([] === $windows) {
            return [];
        }

        $now = Time::nowUtc();
        $states = [];
        $items = [];

        foreach ($windows as $window) {
            $playlist = $window['playlist'];
            if (PlaylistSources::Songs !== $playlist->source) {
                // Remote streams/playlists do not expose StationMedia rows that can
                // truthfully populate song metadata in queue/report surfaces.
                continue;
            }

            $playlistId = $playlist->id;
            if (!isset($states[$playlistId])) {
                $states[$playlistId] = $this->loadPlaylistState($station, $playlist);
            }

            /** @var array{remaining: list<array{media: StationMedia, duration: float}>, cycle: list<array{media: StationMedia, duration: float}>, remaining_seconds: float|null} $state */
            $state = &$states[$playlistId];
            if ([] === $state['cycle']) {
                unset($state);
                continue;
            }

            $cursor = CarbonImmutable::instance($window['start']);
            if ($cursor < $rangeStart) {
                $cursor = CarbonImmutable::instance($rangeStart);
            }

            $isActiveNow = $window['start']->getTimestamp() <= $now->getTimestamp()
                && $window['end']->getTimestamp() > $now->getTimestamp();

            if ($isActiveNow && null !== $state['remaining_seconds']) {
                $cursor = CarbonImmutable::instance($now)->addSeconds(
                    (int)max(0, ceil($state['remaining_seconds']))
                );
                if ($cursor < $rangeStart) {
                    $cursor = CarbonImmutable::instance($rangeStart);
                }
                $state['remaining_seconds'] = null;
            }

            while ($cursor < $window['end'] && count($items) < $limit) {
                if ([] === $state['remaining']) {
                    // Rigid song sources run in deterministic normal mode. Once a
                    // full round is exhausted, Liquidsoap loops the same source
                    // file order; repeat that same cycle here so future rows remain
                    // identical to what the station will actually air.
                    $state['remaining'] = $state['cycle'];
                }

                $next = array_shift($state['remaining']);
                $playedAt = $cursor;
                $availableSeconds = max(
                    0,
                    $window['end']->getTimestamp() - $playedAt->getTimestamp()
                );
                if (0 === $availableSeconds) {
                    break;
                }

                $duration = min($next['duration'], (float)$availableSeconds);
                $items[] = new RigidScheduleForecastItem(
                    playlist: $playlist,
                    schedule: $window['schedule'],
                    media: $next['media'],
                    playedAt: $playedAt,
                    duration: $duration,
                );

                $cursor = $cursor->addSeconds((int)max(1, ceil($duration)));
            }

            unset($state);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function toQueueRow(
        Station $station,
        RigidScheduleForecastItem $item,
    ): StationQueue {
        $row = StationQueue::fromMedia($station, $item->media);
        $row->playlist = $item->playlist;
        $row->timestamp_cued = $item->playedAt;
        $row->timestamp_played = $item->playedAt;
        $row->duration = $item->duration;

        // This is a read-only virtual row representing a Liquidsoap-owned source,
        // not an ordinary AutoDJ row that may be deleted or sent by nextsong.
        $row->sent_to_autodj = true;
        $row->is_visible = true;

        return $row;
    }

    /**
     * @return array{remaining: list<array{media: StationMedia, duration: float}>, cycle: list<array{media: StationMedia, duration: float}>, remaining_seconds: float|null}
     */
    private function loadPlaylistState(Station $station, StationPlaylist $playlist): array
    {
        $media = $this->getPlaylistMedia($playlist);
        $mediaById = [];
        $fallbackCycle = [];

        foreach ($media as $mediaRow) {
            $mediaById[$mediaRow->id] = $mediaRow;
            $fallbackCycle[] = [
                'media' => $mediaRow,
                'duration' => max(1.0, $mediaRow->getCalculatedLength()),
            ];
        }

        try {
            $sourceId = $this->getSourceId($playlist);
            $response = $this->liquidsoap->command($station, $sourceId . '.forecast');
            $payload = $this->decodeForecastResponse($response);

            $cycle = $this->mapUrisToMedia(
                is_array($payload['all_files'] ?? null) ? $payload['all_files'] : [],
                $mediaById,
            );
            $remaining = $this->mapUrisToMedia(
                is_array($payload['remaining_files'] ?? null) ? $payload['remaining_files'] : [],
                $mediaById,
            );

            if ([] === $cycle) {
                $cycle = $fallbackCycle;
            }
            if ([] === $remaining) {
                $remaining = $cycle;
            }

            return [
                'remaining' => $remaining,
                'cycle' => $cycle,
                'remaining_seconds' => isset($payload['remaining_seconds'])
                    ? max(0.0, (float)$payload['remaining_seconds'])
                    : null,
            ];
        } catch (Exception|JsonException) {
            // If Liquidsoap is between restarts, keep reporting useful playlist
            // rows from the exact M3U/DB order rather than falling back to an
            // unrelated ordinary AutoDJ queue.
            return [
                'remaining' => $fallbackCycle,
                'cycle' => $fallbackCycle,
                'remaining_seconds' => null,
            ];
        }
    }

    /**
     * @param string[] $response
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function decodeForecastResponse(array $response): array
    {
        foreach ($response as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : [];
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $uris
     * @param array<int, StationMedia> $mediaById
     * @return list<array{media: StationMedia, duration: float}>
     */
    private function mapUrisToMedia(array $uris, array $mediaById): array
    {
        $result = [];

        foreach ($uris as $uri) {
            if (!is_string($uri)) {
                continue;
            }

            if (!preg_match('/media_id[^0-9]*(\d+)/', $uri, $matches)) {
                continue;
            }

            $mediaId = (int)$matches[1];
            $media = $mediaById[$mediaId] ?? null;
            if (!$media instanceof StationMedia) {
                continue;
            }

            $duration = max(1.0, $media->getCalculatedLength());
            if (preg_match('/duration[^0-9]*([0-9]+(?:\.[0-9]+)?)/', $uri, $durationMatch)) {
                $duration = max(1.0, (float)$durationMatch[1]);
            }

            $result[] = [
                'media' => $media,
                'duration' => $duration,
            ];
        }

        return $result;
    }

    /** @return list<StationMedia> */
    private function getPlaylistMedia(StationPlaylist $playlist): array
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT DISTINCT sm
                FROM App\Entity\StationMedia sm
                JOIN sm.playlists spm
                WHERE spm.playlist = :playlist
                ORDER BY spm.weight ASC
            DQL
        )->setParameter('playlist', $playlist)
            ->getResult();
    }

    private function getSourceId(StationPlaylist $playlist): string
    {
        return 'rigid_' . ConfigWriter::getPlaylistVariableName($playlist) . '_' . $playlist->id;
    }
}
