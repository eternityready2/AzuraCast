<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

use App\Cache\AutoCueCache;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Scores every Top-of-Hour ID in a period from the air history: how the last
 * song or spot of the hour met the ID, whether the ID itself was on time, and
 * what happened right after it.
 *
 * Read-only. Nothing is stored: the report reaches back only as far as the
 * station's song history is kept, so there is no log of its own to clean up.
 */
final class TopOfHourLandingReport
{
    public const int DEFAULT_DAYS = 7;
    public const int MAX_DAYS = 30;

    /** The share of hours that should end cleanly. */
    public const int TARGET_PERCENT = 90;

    /** The tempo fit may play a song this much faster or slower to land it. */
    private const float FIT_TEMPO_FRACTION = 0.03;

    /** An ID this far from its target is not that hour's Top-of-Hour ID. */
    private const float ID_WINDOW_SECONDS = 90.0;

    /** Longer than this, the item before the ID is a show, not a song or spot. */
    private const float SHOW_MIN_SECONDS = 900.0;

    /** Seconds off target (lost to the ID, left empty, early, late) that count as a miss. */
    private const float MISS_SECONDS = 2.0;

    /** Seconds trimmed off a song's real ending that count as sent shortened. */
    private const float SHORTENED_SECONDS = 3.0;

    /** A song starting with less than this before the ID started too late to fit. */
    private const float LATE_START_SECONDS = 135.0;

    /** How long after the ID the item before it is watched for coming back. */
    private const float RESUME_WINDOW_SECONDS = 600.0;

    /** Shorter than this on air is a metadata blip, not a song that came back. */
    private const float RESUME_MIN_SECONDS = 2.0;

    /** Past this, the history simply has no row for what followed the ID (a show or feed). */
    private const float AFTER_ID_MAX_GAP_SECONDS = 60.0;

    /** How far a swapped log line's air time may sit from the history row it became. */
    private const int SWAP_MATCH_SECONDS = 6;

    /** Aired length assumed trimmed off a file's end when AutoCue has no cue-out. */
    private const float DEFAULT_TAIL_SECONDS = 3.5;

    private const int WORST_HOURS_LISTED = 6;

    private const array SONG_OR_SPOT_TYPES = [
        ClockWheelSlotTypes::Music->value,
        ClockWheelSlotTypes::Promo->value,
        ClockWheelSlotTypes::Ad->value,
    ];

    private const array SPOT_TYPES = [
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
     * @return array<string, mixed>
     */
    public function buildForDays(Station $station, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $now = time();

        return $this->build($station, $now - $days * 86400, $now) + ['days' => $days];
    }

    /**
     * Scores the IDs that aired from $from up to $to (Unix time), and the
     * period of the same length just before it for the trend.
     *
     * @return array<string, mixed>
     */
    public function build(Station $station, int $from, int $to): array
    {
        $to = min($to, time());
        $from = min($from, $to);
        $previousFrom = $from - ($to - $from);
        $tz = $station->getTimezoneObject();

        $rows = $this->historyRows($station, $previousFrom - 3600, $to + (int)self::RESUME_WINDOW_SECONDS);
        $targets = $this->recordedTargets($station, $previousFrom - 3600, $to + 3600);
        $swapped = $this->swappedAirTimes($station, $previousFrom - 3600, $to);
        $idOffset = $this->clock->getIdStartMinute($station) * 60 + $this->clock->getIdStartSecond($station);

        $c = array_fill_keys([
            'id_hours', 'show', 'clean', 'cut', 'ended_early', 'resumed_after_cut', 'replayed',
            'swap', 'swap_clean', 'tempo', 'tempo_clean', 'id_early', 'id_early_show', 'id_late',
            'after', 'late_start', 'under_id', 'promo_stack', 'previous_music', 'previous_clean',
        ], 0);
        $byHour = [];
        $failures = [];

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $id = $rows[$i];
            $before = $rows[$i - 1];
            $idAt = (float)$id['ts'];

            if (
                $idAt < $previousFrom
                || $idAt >= $to
                || !StationMediaTypes::isStationId($id['media_type'])
                || StationMediaTypes::isStationId($before['media_type'])
            ) {
                continue;
            }

            // The target the ID was staged for that hour; the setting may have changed since.
            $target = $targets[(int)round($idAt / 3600)] ?? $this->nearestTarget($idAt, $idOffset);
            if (abs($idAt - $target) > self::ID_WINDOW_SECONDS) {
                continue;
            }

            $landing = $this->scoreLanding($before, $target);

            if ($idAt < $from) {
                if (null !== $landing) {
                    $c['previous_music']++;
                    $c['previous_clean'] += null === $landing['kind'] ? 1 : 0;
                }
                continue;
            }

            $c['id_hours']++;
            $failure = [
                'kind' => null,
                'seconds' => 0.0,
                'resumed' => null,
                'id_offset_seconds' => 0.0,
                'late_start_seconds' => 0.0,
                'under_id' => false,
                'promo_stack' => false,
            ];

            if (null === $landing) {
                $c['show']++;
            } else {
                $failure['kind'] = $landing['kind'];
                $failure['seconds'] = round($landing['seconds'], 1);
                $isClean = null === $landing['kind'];

                $c[match ($landing['kind']) {
                    null => 'clean',
                    'ended_early' => 'ended_early',
                    default => 'cut',
                }]++;

                if (isset($swapped[(int)round((float)$before['ts'])])) {
                    $c['swap']++;
                    $c['swap_clean'] += $isClean ? 1 : 0;
                }
                if ($landing['needed_tempo']) {
                    $c['tempo']++;
                    $c['tempo_clean'] += $isClean ? 1 : 0;
                }

                $hour = (int)new DateTimeImmutable('@' . (int)(round($target / 3600) * 3600 - 3600))
                    ->setTimezone($tz)
                    ->format('G');
                $byHour[$hour] ??= ['hour' => $hour, 'music_hours' => 0, 'missed' => 0];
                $byHour[$hour]['music_hours']++;
                $byHour[$hour]['missed'] += $isClean ? 0 : 1;

                if ($this->cameBackAfterId($rows, $i, (int)$before['media_id'])) {
                    $wasCut = !$isClean && 'ended_early' !== $landing['kind'];
                    $failure['resumed'] = $wasCut ? 'after_cut' : 'replayed';
                    $c[$wasCut ? 'resumed_after_cut' : 'replayed']++;
                }
            }

            $offset = $idAt - $target;
            if (abs($offset) >= self::MISS_SECONDS) {
                $failure['id_offset_seconds'] = round($offset, 1);
                $c[$offset < 0 ? 'id_early' : 'id_late']++;
                $c['id_early_show'] += $offset < 0 && null === $landing ? 1 : 0;
            }

            $after = $rows[$i + 1] ?? null;
            $idLength = $this->cueFor((int)$id['media_id'])['file'] ?? null;
            if (null !== $after && null !== $idLength && !StationMediaTypes::isStationId($after['media_type'])) {
                $c['after']++;
                $gap = (float)$after['ts'] - ($idAt + $idLength);

                if ($gap <= -self::MISS_SECONDS) {
                    $failure['under_id'] = true;
                    $c['under_id']++;
                } elseif (
                    null !== $after['media_id']
                    && $gap >= self::MISS_SECONDS
                    && $gap <= self::AFTER_ID_MAX_GAP_SECONDS
                ) {
                    $failure['late_start_seconds'] = round($gap, 1);
                    $c['late_start']++;
                }
            }

            if (
                in_array($before['media_type'], self::SPOT_TYPES, true)
                && in_array($rows[$i - 2]['media_type'] ?? null, self::SPOT_TYPES, true)
            ) {
                $failure['promo_stack'] = true;
                $c['promo_stack']++;
            }

            if (
                null !== $failure['kind']
                || null !== $failure['resumed']
                || 0.0 !== $failure['id_offset_seconds']
                || 0.0 !== $failure['late_start_seconds']
                || $failure['under_id']
                || $failure['promo_stack']
            ) {
                $failures[] = [
                    'id_at' => $this->iso($idAt, $tz),
                    'title' => $this->titleOf($before),
                    ...$failure,
                    'aired' => $this->airedAround($rows, $i, $tz),
                ];
            }
        }

        $music = $c['clean'] + $c['cut'] + $c['ended_early'];
        $percent = static fn(int $count, int $of): ?float => $of > 0 ? round(100 * $count / $of, 1) : null;

        usort(
            $byHour,
            static fn(array $a, array $b): int => [$b['missed'], $a['music_hours'], $a['hour']]
                <=> [$a['missed'], $b['music_hours'], $b['hour']]
        );
        $worstHours = array_slice(
            array_values(array_filter($byHour, static fn(array $hour): bool => $hour['missed'] > 0)),
            0,
            self::WORST_HOURS_LISTED
        );

        return [
            'start' => $this->iso($from, $tz),
            'end' => $this->iso($to, $tz),
            'target_percent' => self::TARGET_PERCENT,
            'id_hours' => $c['id_hours'],
            'music_hours' => $music,
            'show_hours' => $c['show'],
            'clean_count' => $c['clean'],
            'clean_percent' => $percent($c['clean'], $music),
            'previous_music_hours' => $c['previous_music'],
            'previous_clean_percent' => $percent($c['previous_clean'], $c['previous_music']),
            'cut_count' => $c['cut'],
            'cut_percent' => $percent($c['cut'], $music),
            'early_count' => $c['ended_early'],
            'early_percent' => $percent($c['ended_early'], $music),
            'resumed_after_cut_count' => $c['resumed_after_cut'],
            'resumed_after_cut_percent' => $percent($c['resumed_after_cut'], $music),
            'replayed_count' => $c['replayed'],
            'replayed_percent' => $percent($c['replayed'], $music),
            'swap_hours' => $c['swap'],
            'swap_clean_count' => $c['swap_clean'],
            'swap_clean_percent' => $percent($c['swap_clean'], $c['swap']),
            'tempo_hours' => $c['tempo'],
            'tempo_clean_count' => $c['tempo_clean'],
            'tempo_clean_percent' => $percent($c['tempo_clean'], $c['tempo']),
            'id_early_count' => $c['id_early'],
            'id_early_percent' => $percent($c['id_early'], $c['id_hours']),
            'id_early_show_count' => $c['id_early_show'],
            'id_late_count' => $c['id_late'],
            'id_late_percent' => $percent($c['id_late'], $c['id_hours']),
            'after_id_hours' => $c['after'],
            'late_start_count' => $c['late_start'],
            'late_start_percent' => $percent($c['late_start'], $c['after']),
            'under_id_count' => $c['under_id'],
            'under_id_percent' => $percent($c['under_id'], $c['after']),
            'promo_stack_count' => $c['promo_stack'],
            'promo_stack_percent' => $percent($c['promo_stack'], $c['id_hours']),
            'worst_hours' => $worstHours,
            'failures' => array_reverse($failures),
        ];
    }

    /**
     * How the item before the ID met the ID's target, or null when it is a show
     * or feed, which this report does not score.
     *
     * @param array<string, mixed> $before
     * @return array{kind: string|null, seconds: float, needed_tempo: bool}|null
     */
    private function scoreLanding(array $before, float $target): ?array
    {
        $sent = (float)$before['duration'];
        $remaining = $target - (float)$before['ts'];
        $cue = null !== $before['media_id'] ? $this->cueFor((int)$before['media_id']) : null;

        if (
            null === $cue
            || $remaining <= 0
            || $sent > self::SHOW_MIN_SECONDS
            || !in_array($before['media_type'], self::SONG_OR_SPOT_TYPES, true)
        ) {
            return null;
        }

        // A row carries the whole file or a cue-out point; shorter than the
        // real cue-out means the song was sent with its ending trimmed off.
        $shortened = $sent < $cue['file'] - 0.5;
        $trimmed = $shortened ? max(0.0, $cue['out'] - $sent) : 0.0;
        $aired = ($shortened ? min($sent, $cue['out']) : $cue['out']) - $cue['in'];

        $lostToId = max(0.0, $aired / (1.0 + self::FIT_TEMPO_FRACTION) - $remaining);
        $gap = max(0.0, $remaining - $aired / (1.0 - self::FIT_TEMPO_FRACTION));

        [$kind, $seconds] = match (true) {
            $trimmed >= self::SHORTENED_SECONDS => ['cut_short', $trimmed],
            $lostToId >= self::MISS_SECONDS => [
                $remaining < self::LATE_START_SECONDS ? 'cut_late_start' : 'cut_too_long',
                $lostToId,
            ],
            $gap >= self::MISS_SECONDS => ['ended_early', $gap],
            default => [null, 0.0],
        };

        return [
            'kind' => $kind,
            'seconds' => $seconds,
            'needed_tempo' => abs($aired - $remaining) > self::MISS_SECONDS && 'cut_late_start' !== $kind,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRows(Station $station, int $from, int $to): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT h.media_id, h.duration, h.title, h.artist, m.type AS media_type,
                    TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', h.timestamp_start) / 1e6 AS ts
                FROM song_history h
                LEFT JOIN station_media m ON m.id = h.media_id
                WHERE h.station_id = :station AND h.timestamp_start >= :from AND h.timestamp_start <= :to
                ORDER BY h.timestamp_start, h.id
            SQL,
            [
                'station' => $station->id,
                'from' => gmdate('Y-m-d H:i:s', $from),
                'to' => gmdate('Y-m-d H:i:s', $to),
            ]
        );
    }

    /**
     * The ID target recorded when each hour's ID was staged, keyed by the
     * clock hour (Unix time / 3600) nearest to it.
     *
     * @return array<int, float>
     */
    private function recordedTargets(Station $station, int $from, int $to): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', e.expected_play_at)
                FROM clock_wheel_events e
                WHERE e.station_id = :station AND e.clock_wheel_id IS NULL
                AND e.anchor_type = 'legal_id' AND e.event_kind = 'track_queued'
                AND e.expected_play_at >= :from AND e.expected_play_at <= :to
            SQL,
            [
                'station' => $station->id,
                'from' => gmdate('Y-m-d H:i:s', $from),
                'to' => gmdate('Y-m-d H:i:s', $to),
            ]
        );

        $targets = [];
        foreach ($rows as $expected) {
            $targets[(int)round((float)$expected / 3600)] = (float)$expected;
        }

        return $targets;
    }

    /**
     * Every second near the air time of a log line the Top-of-Hour swap put in.
     *
     * @return array<int, true>
     */
    private function swappedAirTimes(Station $station, int $from, int $to): array
    {
        $airedAt = $this->em->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT aired_at FROM station_log_entries
                WHERE station_id = :station AND status = 'swapped'
                AND aired_at >= :from AND aired_at <= :to
            SQL,
            ['station' => $station->id, 'from' => $from, 'to' => $to]
        );

        $seconds = [];
        foreach ($airedAt as $at) {
            for ($s = (int)$at - self::SWAP_MATCH_SECONDS; $s <= (int)$at + self::SWAP_MATCH_SECONDS; $s++) {
                $seconds[$s] = true;
            }
        }

        return $seconds;
    }

    /**
     * The ID target closest to the moment an ID went to air, by today's setting.
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

            if ($this->onAirSeconds($rows, $j) >= self::RESUME_MIN_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * What aired around an ID: the two items before it, the ID, and the two after.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{at: string, title: string, seconds: float, is_id: bool}>
     */
    private function airedAround(array $rows, int $idIndex, DateTimeZone $tz): array
    {
        $aired = [];
        for ($j = max(0, $idIndex - 2), $last = min(count($rows) - 1, $idIndex + 2); $j <= $last; $j++) {
            $aired[] = [
                'at' => $this->iso((float)$rows[$j]['ts'], $tz),
                'title' => $this->titleOf($rows[$j]),
                'seconds' => round($this->onAirSeconds($rows, $j), 1),
                'is_id' => $j === $idIndex,
            ];
        }

        return $aired;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function onAirSeconds(array $rows, int $index): float
    {
        return isset($rows[$index + 1])
            ? (float)$rows[$index + 1]['ts'] - (float)$rows[$index]['ts']
            : (float)$rows[$index]['duration'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function titleOf(array $row): string
    {
        return trim(implode(' - ', array_filter([(string)$row['artist'], (string)$row['title']])));
    }

    private function iso(float|int $at, DateTimeZone $tz): string
    {
        return new DateTimeImmutable('@' . (int)round($at))
            ->setTimezone($tz)
            ->format(DateTimeImmutable::ATOM);
    }
}
