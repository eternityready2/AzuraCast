<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

use App\Cache\AutoCueCache;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Scores how the last song or spot of each hour met the Top-of-Hour ID, from
 * the air history: it ended on its own, the ID cut it, it ended early and left
 * a gap, or it came back on air after the ID.
 *
 * Read-only. Nothing is stored: the report reaches back only as far as the
 * station's song history is kept, so there is no log of its own to clean up.
 */
final class TopOfHourLandingReport
{
    public const int DEFAULT_DAYS = 7;
    public const int MAX_DAYS = 30;

    /** The tempo fit may play a song this much faster or slower to land it. */
    private const float FIT_TEMPO_FRACTION = 0.03;

    /** An ID this far from its :59:ss target is not that hour's Top-of-Hour ID. */
    private const float ID_WINDOW_SECONDS = 90.0;

    /** Longer than this, the item before the ID is a show, not a song or spot. */
    private const float SHOW_MIN_SECONDS = 900.0;

    /** Seconds lost to the ID, or left empty before it, that count as a miss. */
    private const float MISS_SECONDS = 2.0;

    /** Seconds trimmed off a song's real ending that count as sent shortened. */
    private const float SHORTENED_SECONDS = 3.0;

    /** A song starting with less than this before the ID started too late to fit. */
    private const float LATE_START_SECONDS = 135.0;

    /** How long after the ID the item before it is watched for coming back. */
    private const float RESUME_WINDOW_SECONDS = 600.0;

    /** Shorter than this on air is a metadata blip, not a song that came back. */
    private const float RESUME_MIN_SECONDS = 2.0;

    /** Aired length assumed trimmed off a file's end when AutoCue has no cue-out. */
    private const float DEFAULT_TAIL_SECONDS = 3.5;

    private const array SONG_OR_SPOT_TYPES = [
        ClockWheelSlotTypes::Music->value,
        ClockWheelSlotTypes::Promo->value,
        ClockWheelSlotTypes::Ad->value,
    ];

    /** @var array<int, array{in: float, out: float, file: float}|null> */
    private array $cues = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AutoCueCache $autoCueCache,
        private readonly TopOfHourClock $clock,
    ) {
    }

    /**
     * @return array{
     *     days: int,
     *     music_hours: int,
     *     show_hours: int,
     *     clean_count: int,
     *     clean_percent: float|null,
     *     cut_count: int,
     *     cut_percent: float|null,
     *     early_count: int,
     *     early_percent: float|null,
     *     resumed_count: int,
     *     resumed_percent: float|null,
     *     failures: list<array{
     *         id_at: string,
     *         title: string,
     *         kind: string|null,
     *         seconds: float,
     *         resumed: bool
     *     }>
     * }
     */
    public function build(Station $station, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $since = time() - $days * 86400;
        $idOffset = $this->clock->getIdStartMinute($station) * 60 + $this->clock->getIdStartSecond($station);
        $tz = $station->getTimezoneObject();

        // One hour further back, so the first ID in range still has the item before it.
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT h.media_id, h.duration, h.title, h.artist, m.type AS media_type,
                    TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', h.timestamp_start) / 1e6 AS ts
                FROM song_history h
                LEFT JOIN station_media m ON m.id = h.media_id
                WHERE h.station_id = :station AND h.timestamp_start >= :since
                ORDER BY h.timestamp_start, h.id
            SQL,
            [
                'station' => $station->id,
                'since' => gmdate('Y-m-d H:i:s', $since - 3600),
            ]
        );

        $counts = ['clean' => 0, 'cut' => 0, 'early' => 0, 'resumed' => 0, 'show' => 0];
        $failures = [];

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $id = $rows[$i];
            $before = $rows[$i - 1];
            $idAt = (float)$id['ts'];

            if (
                $idAt < $since
                || !StationMediaTypes::isStationId($id['media_type'])
                || StationMediaTypes::isStationId($before['media_type'])
            ) {
                continue;
            }

            $target = $this->nearestTarget($idAt, $idOffset);
            if (abs($idAt - $target) > self::ID_WINDOW_SECONDS) {
                continue;
            }

            $sent = (float)$before['duration'];
            $remaining = $target - (float)$before['ts'];
            $mediaId = null !== $before['media_id'] ? (int)$before['media_id'] : null;
            $cue = null !== $mediaId ? $this->cueFor($mediaId) : null;

            if (
                null === $mediaId
                || null === $cue
                || $remaining <= 0
                || $sent > self::SHOW_MIN_SECONDS
                || !in_array($before['media_type'], self::SONG_OR_SPOT_TYPES, true)
            ) {
                $counts['show']++;
                continue;
            }

            // A row carries the whole file or a cue-out point; shorter than the
            // real cue-out means the song was sent with its ending trimmed off.
            $realLength = $cue['out'] - $cue['in'];
            $shortened = $sent < $cue['file'] - 0.5;
            $trimmed = $shortened ? max(0.0, $cue['out'] - $sent) : 0.0;
            $aired = $shortened ? min($sent, $cue['out']) - $cue['in'] : $realLength;

            $lostToId = max(0.0, $aired / (1.0 + self::FIT_TEMPO_FRACTION) - $remaining);
            $gap = max(0.0, $remaining - $aired / (1.0 - self::FIT_TEMPO_FRACTION));

            $kind = null;
            $seconds = 0.0;
            if ($trimmed >= self::SHORTENED_SECONDS) {
                $kind = 'cut_short';
                $seconds = $trimmed;
                $counts['cut']++;
            } elseif ($lostToId >= self::MISS_SECONDS) {
                $kind = $remaining < self::LATE_START_SECONDS ? 'cut_late_start' : 'cut_too_long';
                $seconds = $lostToId;
                $counts['cut']++;
            } elseif ($gap >= self::MISS_SECONDS) {
                $kind = 'ended_early';
                $seconds = $gap;
                $counts['early']++;
            } else {
                $counts['clean']++;
            }

            $resumed = $this->cameBackAfterId($rows, $i, $mediaId);
            if ($resumed) {
                $counts['resumed']++;
            }

            if (null !== $kind || $resumed) {
                $failures[] = [
                    'id_at' => new DateTimeImmutable('@' . (int)round($idAt))
                        ->setTimezone($tz)
                        ->format(DateTimeImmutable::ATOM),
                    'title' => trim(
                        implode(' - ', array_filter([(string)$before['artist'], (string)$before['title']]))
                    ),
                    'kind' => $kind,
                    'seconds' => round($seconds, 1),
                    'resumed' => $resumed,
                ];
            }
        }

        $musicHours = $counts['clean'] + $counts['cut'] + $counts['early'];
        $percent = static fn(int $count): ?float => $musicHours > 0
            ? round(100 * $count / $musicHours, 1)
            : null;

        return [
            'days' => $days,
            'music_hours' => $musicHours,
            'show_hours' => $counts['show'],
            'clean_count' => $counts['clean'],
            'clean_percent' => $percent($counts['clean']),
            'cut_count' => $counts['cut'],
            'cut_percent' => $percent($counts['cut']),
            'early_count' => $counts['early'],
            'early_percent' => $percent($counts['early']),
            'resumed_count' => $counts['resumed'],
            'resumed_percent' => $percent($counts['resumed']),
            'failures' => array_reverse($failures),
        ];
    }

    /**
     * The :59:ss ID target closest to the moment an ID went to air.
     */
    private function nearestTarget(float $idAt, int $idOffset): float
    {
        $target = floor($idAt / 3600) * 3600 + $idOffset;
        if ($idAt - $target > 1800) {
            $target += 3600;
        } elseif ($target - $idAt > 1800) {
            $target -= 3600;
        }

        return $target;
    }

    /**
     * Where a file really starts and ends on air, as AutoCue measured it.
     *
     * @return array{in: float, out: float, file: float}|null
     */
    private function cueFor(int $mediaId): ?array
    {
        if (array_key_exists($mediaId, $this->cues)) {
            return $this->cues[$mediaId];
        }

        $media = $this->em->find(StationMedia::class, $mediaId);
        if (!$media instanceof StationMedia) {
            return $this->cues[$mediaId] = null;
        }

        $file = $media->getCalculatedLength();
        $cached = $this->autoCueCache->getForCacheKey($this->autoCueCache->getCacheKey($media));

        if (null !== $cached && is_numeric($cached['cue_out'] ?? null)) {
            return $this->cues[$mediaId] = [
                'in' => is_numeric($cached['cue_in'] ?? null) ? (float)$cached['cue_in'] : 0.0,
                'out' => (float)$cached['cue_out'],
                'file' => $file,
            ];
        }

        return $this->cues[$mediaId] = [
            'in' => 0.0,
            'out' => max(0.0, $file - self::DEFAULT_TAIL_SECONDS),
            'file' => $file,
        ];
    }

    /**
     * Whether the item that led into the ID at $rows[$idIndex] was on air again
     * shortly after it.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function cameBackAfterId(array $rows, int $idIndex, int $mediaId): bool
    {
        $idAt = (float)$rows[$idIndex]['ts'];

        for ($j = $idIndex + 1, $n = count($rows); $j < $n; $j++) {
            $startedAt = (float)$rows[$j]['ts'];
            if ($startedAt - $idAt > self::RESUME_WINDOW_SECONDS) {
                break;
            }

            if (null === $rows[$j]['media_id'] || (int)$rows[$j]['media_id'] !== $mediaId) {
                continue;
            }

            $onAir = isset($rows[$j + 1])
                ? (float)$rows[$j + 1]['ts'] - $startedAt
                : (float)$rows[$j]['duration'];
            if ($onAir >= self::RESUME_MIN_SECONDS) {
                return true;
            }
        }

        return false;
    }
}
