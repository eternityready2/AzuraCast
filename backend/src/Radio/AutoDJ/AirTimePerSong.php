<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;

/**
 * How much longer, or shorter, a song really holds the air than its file is
 * long, by the hour it airs in: whatever airs between songs (talk, liners), the
 * crossfade, and AutoCue's trimmed starts and ends, measured from what this
 * station actually aired.
 *
 * The log timed every song at its file length. Measured over the week to Mon
 * 2026-10-12, a song took 9-16s more than its file on weekday nights, 20-28s
 * more on weekend nights, 2-7s more on weekday afternoons, and 3-5s less in the
 * evenings: hours ran 2-6 minutes over or about a minute short, the page showed
 * it every day, and the Top-of-Hour swap had to rescue each hour at the ID.
 * Every place that works out when a line will air takes this from here, so the
 * plan, the live queue, the top-up and the page agree with the air and with
 * each other.
 */
final class AirTimePerSong
{
    use EntityManagerAwareTrait;

    /**
     * How far back what aired is measured. Backtested on Oct 5-12 2026 (each day
     * predicted from the days before it): 7 days, weekday and weekend apart, put
     * 73 of 153 hours within 30s of what aired and missed by 67s on average,
     * against 9 hours and 106s for timing by file length; 14 days, or each day of
     * the week apart, followed the overnight talk changing that week more slowly.
     */
    private const int DAYS = 7;

    /** Fewer songs than this in a slot say too little; the same hour on every day is used. */
    private const int MIN_SONGS = 20;

    /** A next song further off than this is not a song change (a restart, a skip, dead air). */
    private const float MAX_GAP_SECONDS = 600.0;
    private const float MIN_GAP_SECONDS = -60.0;

    /** Bounds on the answer, so one odd fortnight cannot unbalance a whole hour. */
    private const float MIN_SECONDS = -10.0;
    private const float MAX_SECONDS = 45.0;

    /** An item at least this long is a programme, not a song (as the Top-of-Hour swap counts one). */
    private const float PROGRAMME_MIN_SECONDS = 1200.0;

    private const int CACHE_SECONDS = 3600;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Seconds beyond its file length that a song starting at $at holds the air,
     * on average (negative when songs air shorter than their files).
     */
    public function secondsBeyondFile(Station $station, DateTimeInterface|int $at): float
    {
        $timestamp = $at instanceof DateTimeInterface ? $at->getTimestamp() : $at;
        $table = $this->table($station);
        $tz = $station->getTimezoneObject();

        return $table[self::slot($tz, $timestamp)]
            ?? $table[self::slot($tz, $timestamp, true)]
            ?? 0.0;
    }

    /**
     * The measured seconds by slot: weekday or weekend and the hour ("wd13",
     * "we03"), and the hour on every day ("all13") for a slot with too few songs.
     *
     * @return array<string, float>
     */
    public function table(Station $station): array
    {
        $key = 'air_time_per_song_' . $station->id;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var array<string, float> $cached */
            return $cached;
        }

        $table = $this->measure($station);
        $this->cache->set($key, $table, self::CACHE_SECONDS);

        return $table;
    }

    /** @return array<string, float> */
    private function measure(Station $station): array
    {
        /** @var list<array{t: string, media_id: int|string|null, length: string|null, is_programme: int|string|null, title: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', sh.timestamp_start) / 1000000 AS t,
                sh.media_id, sm.length, sp.is_programme, COALESCE(sh.title, \'\') AS title
            FROM song_history sh
            LEFT JOIN station_media sm ON sm.id = sh.media_id
            LEFT JOIN station_playlists sp ON sp.id = sh.playlist_id
            WHERE sh.station_id = ? AND sh.timestamp_start >= ?
            ORDER BY sh.timestamp_start, sh.id',
            [$station->id, gmdate('Y-m-d H:i:s', time() - self::DAYS * 86400)]
        );

        $tz = $station->getTimezoneObject();
        $isSong = static fn(array $row): bool => null !== $row['media_id']
            && (float)$row['length'] > 0.0
            && (float)$row['length'] < self::PROGRAMME_MIN_SECONDS
            && !(bool)$row['is_programme']
            && !str_contains(strtolower($row['title']), 'legal id');

        $gaps = [];
        $count = count($rows);
        foreach ($rows as $i => $row) {
            if (!$isSong($row)) {
                continue;
            }

            // The next item the log plans: talk in between is part of this song's time.
            $next = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (null !== $rows[$j]['media_id']) {
                    $next = $rows[$j];
                    break;
                }
            }
            // The hour's last song is followed by the ID, and a show by its own window.
            if (null === $next || !$isSong($next)) {
                continue;
            }

            $gap = (float)$next['t'] - (float)$row['t'] - (float)$row['length'];
            if ($gap < self::MIN_GAP_SECONDS || $gap > self::MAX_GAP_SECONDS) {
                continue;
            }

            $start = (int)$row['t'];
            $gaps[self::slot($tz, $start)][] = $gap;
            $gaps[self::slot($tz, $start, true)][] = $gap;
        }

        $table = [];
        foreach ($gaps as $slot => $slotGaps) {
            if (count($slotGaps) < self::MIN_SONGS) {
                continue;
            }
            $average = array_sum($slotGaps) / count($slotGaps);
            $table[$slot] = round(max(self::MIN_SECONDS, min(self::MAX_SECONDS, $average)), 1);
        }

        return $table;
    }

    private static function slot(DateTimeZone $tz, int $timestamp, bool $everyDay = false): string
    {
        $local = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        $hour = $local->format('H');
        if ($everyDay) {
            return 'all' . $hour;
        }

        return ((int)$local->format('N') >= 6 ? 'we' : 'wd') . $hour;
    }
}
