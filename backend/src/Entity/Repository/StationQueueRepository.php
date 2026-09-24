<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Interfaces\SongInterface;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStationBasedRepository<StationQueue>
 */
final class StationQueueRepository extends AbstractStationBasedRepository
{
    protected string $entityClass = StationQueue::class;

    public function clearForMediaAndPlaylist(
        StationMedia $media,
        StationPlaylist $playlist
    ): void {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.media = :media 
                AND sq.playlist = :playlist
                AND sq.is_played = 0
            DQL
        )->setParameter('media', $media)
            ->setParameter('playlist', $playlist)
            ->execute();
    }

    public function clearForPlaylist(
        StationPlaylist $playlist
    ): void {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.playlist = :playlist
                AND sq.is_played = 0
            DQL
        )->setParameter('playlist', $playlist)
            ->execute();
    }

    public function getNextVisible(Station $station): ?StationQueue
    {
        return $this->getUnplayedBaseQuery($station)
            // Station-wide TOH IDs are pre-staged into a dedicated Liquidsoap
            // lane and are visible in Upcoming Queue, but they are not the next
            // ordinary AutoDJ item until the wall-clock lane actually takes air.
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->andWhere('sq.is_visible = 1')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function trackPlayed(
        Station $station,
        StationQueue $row
    ): void {
        $this->em->createQuery(
            <<<'DQL'
            UPDATE App\Entity\StationQueue sq
            SET sq.timestamp_played = :timestamp
            WHERE sq.station = :station
            AND sq.id = :id
            DQL
        )->setParameter('timestamp', Time::nowUtc())
            ->setParameter('station', $station)
            ->setParameter('id', $row->id)
            ->execute();

        // A Top-of-Hour ID airs from its own lane, not the AutoDJ transport, so it
        // must not sweep the rows queued to open the new hour (e.g. a scheduled show).
        if ($row->top_of_hour_legal_id) {
            $this->em->createQuery(
                <<<'DQL'
                UPDATE App\Entity\StationQueue sq
                SET sq.is_played=1, sq.sent_to_autodj=1
                WHERE sq.station = :station
                AND sq.id = :id
            DQL
            )->setParameter('station', $station)
                ->setParameter('id', $row->id)
                ->execute();

            return;
        }

        $this->em->createQuery(
            <<<'DQL'
            UPDATE App\Entity\StationQueue sq
            SET sq.is_played=1, sq.sent_to_autodj=1
            WHERE sq.station = :station
            AND sq.is_played = 0
            AND (sq.id = :id OR sq.timestamp_cued < :cued)
        DQL
        )->setParameter('station', $station)
            ->setParameter('id', $row->id)
            ->setParameter('cued', $row->timestamp_cued)
            ->execute();
    }

    public function isPlaylistRecentlyPlayed(
        StationPlaylist $playlist,
        ?int $playPerSongs = null
    ): bool {
        $playPerSongs ??= $playlist->play_per_songs;

        $recentPlayedQuery = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sq.playlist) AS playlist_id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND (sq.playlist = :playlist OR sq.is_visible = 1)
                ORDER BY sq.id DESC
            DQL
        )->setParameters([
            'station' => $playlist->station,
            'playlist' => $playlist,
        ])->setMaxResults($playPerSongs);

        $recentPlayedPlaylists = $recentPlayedQuery->getSingleColumnResult();
        return in_array($playlist->id, (array)$recentPlayedPlaylists, true);
    }

    /**
     * @return mixed[]
     */
    public function getRecentlyPlayedByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, sq.album, COALESCE(sm.type, 'music') as media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.timestamp_played >= :threshold
                AND sq.timestamp_played <= :now
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('threshold', $threshold)
            ->setParameter('now', $now)
            ->getArrayResult();
    }

    /**
     * Legal-compliance history (DMCA §114 counting). Unlike getRecentlyPlayedByTimeRange()
     * -- which intentionally includes not-yet-played queued rows for AutoDJ duplicate
     * prevention -- this only returns tracks that have ACTUALLY aired, and only music-type
     * media, so scheduled-but-unplayed picks and non-music items (AI DJ clips, AI News,
     * station IDs) never inflate a DMCA play count.
     */
    public function getPlayedMusicHistoryByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, sq.album, COALESCE(sm.type, 'music') as media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played >= :threshold
                AND sq.media IS NOT NULL
                AND sm.type = 'music'
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('threshold', $threshold)
            ->getArrayResult();
    }

    /**
     * Music history for an isolated Linear Log projection. Includes temporary
     * unplayed rows created inside the preview transaction, bounded to the
     * simulated play time so projected DMCA counting advances exactly with the
     * preview cursor.
     *
     * @return mixed[]
     */
    public function getProjectedMusicHistoryByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, sq.album, COALESCE(sm.type, 'music') as media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.timestamp_played >= :threshold
                AND sq.timestamp_played <= :now
                AND sq.media IS NOT NULL
                AND sm.type = 'music'
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('threshold', $threshold)
            ->setParameter('now', $now)
            ->getArrayResult();
    }

    /**
     * Recent plays with media category for clock wheel category separation (PR9).
     *
     * @return array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null, category_id:int|null}>
     */
    public function getRecentlyPlayedWithCategoryByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, m.category_id
                FROM App\Entity\StationQueue sq
                LEFT JOIN App\Entity\StationMedia m WITH m.song_id = sq.song_id AND m.storage_location = :storageLocation
                WHERE sq.station = :station
                AND sq.timestamp_played >= :threshold
                AND sq.timestamp_played <= :now
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('storageLocation', $station->media_storage_location)
            ->setParameter('threshold', $threshold)
            ->setParameter('now', $now)
            ->getArrayResult();
    }

    /**
     * Ordinary AutoDJ queue only. Externally pre-staged station-wide TOH IDs
     * remain visible through getUnplayedBaseQuery()/QueueController but must not
     * advance or reorder the normal music cursor.
     *
     * @return StationQueue[]
     */
    public function getUnplayedQueue(Station $station): array
    {
        return $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->getQuery()
            ->execute();
    }

    public function hasUnplayedQueue(Station $station): bool
    {
        $result = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.is_played = 0
                AND sq.top_of_hour_legal_id = 0
            DQL
        )->setParameter('station', $station)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $result;
    }

    public function hasTopOfHourLegalIdCuedBetween(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): bool {
        $result = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.top_of_hour_legal_id = 1
                AND sq.timestamp_cued >= :start
                AND sq.timestamp_cued <= :end
            DQL
        )->setParameter('station', $station)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $result;
    }

    /**
     * Timestamps of already-AIRED (is_played = 1) mandatory legal IDs whose
     * timestamp_played falls within the given window.
     *
     * @return DateTimeImmutable[]
     */
    public function getRecentlyPlayedTopOfHourLegalIds(
        Station $station,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.timestamp_played
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played >= :windowStart
                AND sq.timestamp_played <= :windowEnd
                AND (
                    sq.top_of_hour_legal_id = 1
                    OR sm.type IN (:idTypes)
                )
            DQL
        )->setParameter('station', $station)
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->getArrayResult();

        return array_map(
            static fn (array $row): DateTimeImmutable => $row['timestamp_played'],
            $rows
        );
    }

    /**
     * @return array{
     *     tolerance_seconds: int,
     *     hours_with_legal_id: int,
     *     on_time_count: int,
     *     late_count: int,
     *     compliance_percent: float|null,
     *     fallback_count: int,
     *     late_events: array<int, array{expected_play_at: string, actual_play_at: string, drift_seconds: int}>
     * }
     */
    public function getTopOfHourLegalIdComplianceSummary(
        Station $station,
        DateTimeImmutable $since,
        int $toleranceSeconds,
        ?DateTimeImmutable $until = null,
    ): array {
        $until ??= new DateTimeImmutable('now');

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.timestamp_played, COALESCE(sm.type, '') AS media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.top_of_hour_legal_id = 1
                AND sq.timestamp_played >= :since
                AND sq.timestamp_played <= :until
                ORDER BY sq.timestamp_played ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->getArrayResult();

        $onTimeCount = 0;
        $fallbackCount = 0;
        $lateEvents = [];
        $timezone = $station->getTimezoneObject();

        foreach ($rows as $row) {
            if (!$row['timestamp_played'] instanceof \DateTimeInterface) {
                continue;
            }

            $actual = CarbonImmutable::instance($row['timestamp_played'])->setTimezone($timezone);
            $hourStart = $actual->startOfHour();
            $nextHour = $hourStart->addHour();
            $expected = abs($actual->getTimestamp() - $hourStart->getTimestamp())
                <= abs($nextHour->getTimestamp() - $actual->getTimestamp())
                ? $hourStart
                : $nextHour;
            $driftSeconds = abs($actual->getTimestamp() - $expected->getTimestamp());

            if ($driftSeconds <= $toleranceSeconds) {
                $onTimeCount++;
            } else {
                $lateEvents[] = [
                    'expected_play_at' => $expected->format(DateTimeImmutable::ATOM),
                    'actual_play_at' => $actual->format(DateTimeImmutable::ATOM),
                    'drift_seconds' => $driftSeconds,
                ];
            }

            if (!StationMediaTypes::isStationId((string)$row['media_type'])) {
                $fallbackCount++;
            }
        }

        $total = $onTimeCount + count($lateEvents);

        return [
            'tolerance_seconds' => $toleranceSeconds,
            'hours_with_legal_id' => $total,
            'on_time_count' => $onTimeCount,
            'late_count' => count($lateEvents),
            'compliance_percent' => $total > 0 ? round(($onTimeCount / $total) * 100, 1) : null,
            'fallback_count' => $fallbackCount,
            'late_events' => $lateEvents,
        ];
    }

    public function clearUpcomingQueue(Station $station): void
    {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.sent_to_autodj = 0
                AND sq.top_of_hour_legal_id = 0
            DQL
        )->setParameter('station', $station)
            ->execute();
    }

    /** @return list<StationQueue> */
    public function getNextToSendToAutoDjRows(Station $station, int $limit): array
    {
        return $this->getBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 0')
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->orderBy('sq.timestamp_cued', 'ASC')
            ->getQuery()
            ->setMaxResults($limit)
            ->getResult();
    }

    public function getNextToSendToAutoDj(Station $station): ?StationQueue
    {
        return $this->getBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 0')
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->orderBy('sq.timestamp_cued', 'ASC')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * Give back an AutoDJ pick that was already resolved and marked "sent"
     * but never actually confirmed on air (no feedback call yet), so it is
     * offered again by getNextToSendToAutoDj() instead of being silently
     * skipped from rotation forever.
     *
     * request.dynamic keeps exactly one request resolved ahead of whatever
     * is actually on air so its crossfade has something ready the instant
     * the current track ends. Annotations::postAnnotation() marks that
     * request's StationQueue row "sent" the moment it is resolved -- not
     * when it actually airs. When a broadcast-clock interruption (e.g. the
     * Top-of-Hour Station ID) discards that reserved request so it cannot
     * surface mid-song after the interruption, the row stays marked "sent"
     * and is gone from rotation for good; the row after it opens the new
     * hour instead of the song that was actually queued next.
     *
     * Returns the row it handed back, or null when it deliberately did
     * nothing, so the caller can log the outcome -- this runs during the ID
     * window where nothing else is observable, and a silent no-op here is
     * indistinguishable from the method never being reached at all.
     *
     * Scoped to the last few minutes and to plain ordinary picks so this
     * can never reach back and disturb an unrelated historical row.
     *
     * IMPORTANT: the row currently on air is *also* "sent, unplayed" right
     * up until the next now-playing feedback call marks it played -- and it
     * was cued EARLIER than the one-ahead reserve, because it was resolved
     * back when it was itself the reserve, before the track before it even
     * started. The one-ahead reserve this method actually needs to rescue
     * was resolved only once the (interrupted) track started playing, so it
     * is always the MORE RECENTLY cued of the two. Ordering by oldest first
     * here used to grab the interrupted on-air row instead of the discarded
     * reserve, which rewound the wrong row to the front of the queue: the
     * already-heard track got re-offered as if it were next, silently
     * bumping the song that was actually queued (and shown in Upcoming
     * Queue) to start right after the ID. Ordering by most-recently-cued
     * first targets the reserve, not whatever is airing.
     */
    public function releaseUnairedSentRow(Station $station): ?StationQueue
    {
        $candidates = $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 1')
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->andWhere('sq.clock_wheel_legal_id_substitute = 0')
            ->andWhere('sq.request IS NULL')
            ->andWhere('sq.timestamp_cued >= :cutoff')
            ->setParameter('cutoff', Time::nowUtc()->subMinutes(5))
            ->orderBy('sq.timestamp_cued', 'DESC')
            ->getQuery()
            ->setMaxResults(5)
            ->getResult();

        if ([] === $candidates) {
            return null;
        }

        // A row that has ALREADY been on air must never be released. This is
        // not hypothetical: the interrupted song is still "sent, unplayed"
        // until now-playing feedback marks it played, and this method is
        // called on every refused nextsong request for the whole ~39s ID
        // window -- the first of which lands a fraction of a second after
        // the ID takes air. Once the genuine reserve has been handed back by
        // an earlier call in the same window, the interrupted song is the
        // only remaining match, so a lagging feedback call would otherwise
        // let a track that listeners just heard get rewound to the front of
        // the queue and replayed the moment the ID ends.
        //
        // current_song alone cannot be the guard here, because during the ID
        // window current_song IS the Station ID, not the music it
        // interrupted. song_history is the authoritative record of what
        // actually aired, so it is what gets consulted.
        $airedSongIds = $this->getRecentlyAiredSongIds($station);
        $currentSongId = $station->current_song?->song_id;

        $row = null;
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof StationQueue) {
                continue;
            }

            $songId = $candidate->song_id;

            if (null !== $currentSongId && $songId === $currentSongId) {
                continue;
            }

            if (isset($airedSongIds[$songId])) {
                continue;
            }

            $row = $candidate;
            break;
        }

        // Nothing left that provably never aired. Leaving the queue untouched
        // is the safe outcome: at worst one pick is skipped, which is far less
        // audible than replaying a song that just played.
        if (null === $row) {
            return null;
        }

        $earliestUnsent = $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 0')
            ->orderBy('sq.timestamp_cued', 'ASC')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();

        // Must sort ahead of every still-unsent row so it is what
        // getNextToSendToAutoDj() returns next, not whatever was already
        // queued behind it. postAnnotation() re-stamped timestamp_cued to
        // the resolve time when it marked this row "sent", so its original
        // FIFO position is gone and has to be re-established here.
        $row->timestamp_cued = (null !== $earliestUnsent)
            ? CarbonImmutable::instance($earliestUnsent->timestamp_cued)->subSecond()
            : Time::nowUtc();

        $row->sent_to_autodj = false;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /**
     * Seconds of audio already handed to Liquidsoap but not yet on air.
     *
     * Liquidsoap does not resolve a request only when it is about to play it.
     * The crossfade operator needs the *next* track available to build the
     * transition out of the current one, so by the time a row is resolved
     * there is normally another resolved row queued between it and the air.
     * Measured on this station: a row's resolve time consistently equals the
     * moment the row TWO positions ahead of it starts playing.
     *
     * That matters because a resolved row can no longer be changed -- it is
     * inside Liquidsoap. So the last chance to swap a row is at resolve time
     * (see Annotations::revalidateBeforeSend()), and a decision made there
     * against "the current song ends, then this one plays" is wrong by a
     * whole track. One observed case: a row resolved at 19:46:22 was
     * projected to air at 19:48:53 and actually aired at 19:54:36 -- 5m43s
     * out, which is the difference between "this is the last song before the
     * Station ID, swap it" and "there is another song after this one". It
     * produced a 96-second mid-song chop at the top of the hour.
     *
     * Rows staged into their own Liquidsoap lane (the Top-of-Hour legal ID
     * and clock-wheel ID substitutes) are deliberately NOT counted: they do
     * not occupy the AutoDJ transport, and the TOH ID in particular is
     * pre-staged up to half an hour early, so counting it would push every
     * projection out by its length for the rest of the hour.
     */
    public function getUnairedSentDuration(Station $station): float
    {
        $rows = $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 1')
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->andWhere('sq.clock_wheel_legal_id_substitute = 0')
            ->andWhere('sq.timestamp_cued >= :cutoff')
            ->setParameter('cutoff', Time::nowUtc()->subMinutes(30))
            ->getQuery()
            ->getResult();

        if ([] === $rows) {
            return 0.0;
        }

        // The row currently on air is also "sent, unplayed" until feedback
        // marks it played, and the caller has already accounted for it via
        // the current song's own end time -- counting it here would double it.
        $airedSongIds = $this->getRecentlyAiredSongIds($station);
        $currentSongId = $station->current_song?->song_id;

        $seconds = 0.0;
        foreach ($rows as $row) {
            if (!$row instanceof StationQueue) {
                continue;
            }

            $songId = $row->song_id;

            if (null !== $currentSongId && $songId === $currentSongId) {
                continue;
            }

            if (isset($airedSongIds[$songId])) {
                continue;
            }

            $seconds += (float)($row->duration ?? 0.0);
        }

        return $seconds;
    }

    /**
     * Song IDs this station has actually put to air recently, straight from
     * SongHistory (i.e. what listeners heard), for use as a "this one is
     * already spent" guard. Keyed by song_id for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function getRecentlyAiredSongIds(Station $station, int $minutes = 10): array
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sh.song_id FROM App\Entity\SongHistory sh
                WHERE sh.station = :station
                AND sh.timestamp_start >= :threshold
            DQL
        )->setParameter('station', $station)
            ->setParameter('threshold', Time::nowUtc()->subMinutes($minutes))
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $songId = $row['song_id'] ?? null;
            if (is_string($songId) && '' !== $songId) {
                $ids[$songId] = true;
            }
        }

        return $ids;
    }

    public function findRecentlyCuedSong(
        Station $station,
        SongInterface $song
    ): ?StationQueue {
        return $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 1')
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->andWhere('sq.song_id = :song_id')
            ->setParameter('song_id', $song->song_id)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function hasCuedPlaylistMedia(StationPlaylist $playlist): bool
    {
        $station = $playlist->station;

        $cuedPlaylistContentCountQuery = $this->getUnplayedBaseQuery($station)
            ->select('count(sq.id)')
            ->andWhere('sq.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery();

        $cuedPlaylistContentCount = $cuedPlaylistContentCountQuery->getSingleScalarResult();
        return $cuedPlaylistContentCount > 0;
    }

    public function getUnplayedBaseQuery(Station $station): QueryBuilder
    {
        return $this->getBaseQuery($station)
            ->andWhere('sq.is_played = 0')
            ->orderBy('sq.sent_to_autodj', 'DESC')
            ->addOrderBy('sq.timestamp_cued', 'ASC');
    }

    private function getBaseQuery(Station $station): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('sq, sm, sp, scw')
            ->from(StationQueue::class, 'sq')
            ->leftJoin('sq.media', 'sm')
            ->leftJoin('sq.playlist', 'sp')
            ->leftJoin('sq.clock_wheel', 'scw')
            ->where('sq.station = :station')
            ->setParameter('station', $station);
    }

    public function clearUnplayed(?Station $station = null): void
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(StationQueue::class, 'sq')
            ->where('sq.is_played = 0');

        if (null !== $station) {
            $qb->andWhere('sq.station = :station')
                ->setParameter('station', $station);
        }

        $qb->getQuery()->execute();
    }

    public function cleanup(int $daysToKeep): void
    {
        $threshold = Time::nowUtc()->subDays($daysToKeep);

        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.timestamp_cued <= :threshold
            DQL
        )->setParameter('threshold', $threshold)
            ->execute();
    }
}
