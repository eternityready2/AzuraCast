<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * DMCA Digital Performance Compliance Listener
 *
 * Enforces SoundExchange / DMCA statutory license rules for internet radio:
 *
 *   Rule 1 Ã¢â‚¬â€ No more than 3 plays of the same song in any rolling 3-hour window.
 *   Rule 2 Ã¢â‚¬â€ No more than 2 consecutive plays of the same song.
 *   Rule 3 Ã¢â‚¬â€ No more than 3 songs from the same album in any rolling 3-hour window.
 *   Rule 4 Ã¢â‚¬â€ No more than 4 songs by the same artist in any rolling 3-hour window,
 *             and no more than 3 consecutive songs by the same artist.
 *
 * If the song about to be queued would violate any rule, it is REJECTED and
 * the BuildQueue event is stopped so the normal AutoDJ picks a different track.
 *
 * Fail-safe behavior:
 *   - If history cannot be read, the song is ALLOWED (never block playback on an error).
 *   - All rejections are logged with the reason so you have a full audit trail.
 *
 * Priority -5: runs AFTER QueueBuilder (which selects the song) but BEFORE
 * Annotations (which writes it to Liquidsoap), so we catch it in time.
 */
final class DmcaComplianceListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    /**
     * DMCA rolling window in minutes.
     * Statutory rule is 3 hours.
     */
    private const int WINDOW_MINUTES = 180;

    /**
     * Max plays of the same song_id in the rolling window.
     * DMCA limit: 3 plays per 3-hour period.
     */
    private const int MAX_SONG_PLAYS_PER_WINDOW = 3;

    /**
     * Max plays of the same album in the rolling window.
     * DMCA limit: 3 songs from the same album per 3-hour period.
     */
    private const int MAX_ALBUM_PLAYS_PER_WINDOW = 3;

    /**
     * Max consecutive plays of the same song.
     * DMCA limit: cannot play same recording consecutively more than twice.
     */
    private const int MAX_CONSECUTIVE_SONG_PLAYS = 2;

    /**
     * Max plays by the same artist in the rolling window.
     * DMCA limit: 4 songs by same artist per 3-hour period.
     */
    private const int MAX_ARTIST_PLAYS_PER_WINDOW = 4;

    /**
     * Max consecutive plays by the same artist.
     * DMCA limit: no more than 3 consecutive songs by the same artist.
     */
    private const int MAX_CONSECUTIVE_ARTIST_PLAYS = 3;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority -5: after QueueBuilder selects the song, before Annotations writes it.
        return [
            BuildQueue::class => ['onBuildQueue', -5],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        $station   = $event->getStation();
        $nextSongs = $event->getNextSongs();

        // Nothing queued yet Ã¢â‚¬â€ nothing to check.
        if (empty($nextSongs)) {
            return;
        }

        foreach ($nextSongs as $queueEntry) {
            if (!$this->isCompliant($queueEntry, $station, $event->getExpectedPlayTime())) {
                // Song violates DMCA rules Ã¢â‚¬â€ stop this BuildQueue event so
                // AzuraCast will retry with a different track selection.
                $event->setNextSongs(null); // clear selection so the AutoDJ re-picks (stopPropagation alone does not reject)
                $event->stopPropagation();

                $this->logger->warning(
                    'DMCA Compliance: Rejected song Ã¢â‚¬â€ BuildQueue will retry with a different track.',
                    [
                        'station' => $station->name,
                        'song_id' => $queueEntry->song_id,
                        'title'   => $queueEntry->title,
                        'artist'  => $queueEntry->artist,
                        'album'   => $queueEntry->album,
                    ]
                );

                return;
            }
        }
    }

    private function isCompliant(
        StationQueue $entry,
        Station $station,
        \DateTimeImmutable $expectedPlayTime,
    ): bool {
        try {
            $history = $this->queueRepo->getRecentlyPlayedByTimeRange(
                $station,
                $expectedPlayTime,
                self::WINDOW_MINUTES
            );
        } catch (\Throwable $e) {
            // Fail-safe: if we can't read history, allow the song.
            $this->logger->error(
                'DMCA Compliance: Could not read play history Ã¢â‚¬â€ allowing song as fail-safe.',
                ['error' => $e->getMessage()]
            );
            return true;
        }

        $songId = $entry->song_id;
        $album  = $entry->album ?? null;
        $artist = $entry->artist ?? null;

        // Ã¢â€â‚¬Ã¢â€â‚¬ Rule 1: Max plays of same song in rolling 3-hour window Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
        $songPlays = 0;
        foreach ($history as $row) {
            if ($row['song_id'] === $songId) {
                $songPlays++;
            }
        }

        if ($songPlays >= self::MAX_SONG_PLAYS_PER_WINDOW) {
            $this->logger->info(
                'DMCA Compliance: Song rejected Ã¢â‚¬â€ exceeded max song plays in 3-hour window.',
                [
                    'title'       => $entry->title,
                    'artist'      => $artist,
                    'plays'       => $songPlays,
                    'limit'       => self::MAX_SONG_PLAYS_PER_WINDOW,
                    'window_mins' => self::WINDOW_MINUTES,
                ]
            );
            return false;
        }

        // Ã¢â€â‚¬Ã¢â€â‚¬ Rule 2: No more than N consecutive plays of the same song Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
        $consecutiveSongCount = 0;
        foreach ($history as $row) {
            if ($row['song_id'] === $songId) {
                $consecutiveSongCount++;
            } else {
                break;
            }
        }

        if ($consecutiveSongCount >= self::MAX_CONSECUTIVE_SONG_PLAYS) {
            $this->logger->info(
                'DMCA Compliance: Song rejected Ã¢â‚¬â€ too many consecutive plays of the same song.',
                [
                    'title'       => $entry->title,
                    'artist'      => $artist,
                    'consecutive' => $consecutiveSongCount,
                    'limit'       => self::MAX_CONSECUTIVE_SONG_PLAYS,
                ]
            );
            return false;
        }

        // Ã¢â€â‚¬Ã¢â€â‚¬ Rule 3: Max plays from same album in rolling 3-hour window Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
        if (!empty($album)) {
            $albumPlays = 0;
            foreach ($history as $row) {
                $rowAlbum = $row['album'] ?? null;
                if (!empty($rowAlbum) && strtolower($rowAlbum) === strtolower($album)) {
                    $albumPlays++;
                }
            }

            if ($albumPlays >= self::MAX_ALBUM_PLAYS_PER_WINDOW) {
                $this->logger->info(
                    'DMCA Compliance: Song rejected Ã¢â‚¬â€ album play limit reached in 3-hour window.',
                    [
                        'title'       => $entry->title,
                        'artist'      => $artist,
                        'album'       => $album,
                        'album_plays' => $albumPlays,
                        'limit'       => self::MAX_ALBUM_PLAYS_PER_WINDOW,
                        'window_mins' => self::WINDOW_MINUTES,
                    ]
                );
                return false;
            }
        }

        // Ã¢â€â‚¬Ã¢â€â‚¬ Rule 4a: Max plays by same artist in rolling 3-hour window Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
        if (!empty($artist)) {
            $artistPlays = 0;
            foreach ($history as $row) {
                $rowArtist = $row['artist'] ?? null;
                if (!empty($rowArtist) && strtolower($rowArtist) === strtolower($artist)) {
                    $artistPlays++;
                }
            }

            if ($artistPlays >= self::MAX_ARTIST_PLAYS_PER_WINDOW) {
                $this->logger->info(
                    'DMCA Compliance: Song rejected Ã¢â‚¬â€ artist play limit reached in 3-hour window.',
                    [
                        'title'        => $entry->title,
                        'artist'       => $artist,
                        'artist_plays' => $artistPlays,
                        'limit'        => self::MAX_ARTIST_PLAYS_PER_WINDOW,
                        'window_mins'  => self::WINDOW_MINUTES,
                    ]
                );
                return false;
            }

            // Ã¢â€â‚¬Ã¢â€â‚¬ Rule 4b: Max consecutive plays by same artist Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
            $consecutiveArtistCount = 0;
            foreach ($history as $row) {
                $rowArtist = $row['artist'] ?? null;
                if (!empty($rowArtist) && strtolower($rowArtist) === strtolower($artist)) {
                    $consecutiveArtistCount++;
                } else {
                    break;
                }
            }

            if ($consecutiveArtistCount >= self::MAX_CONSECUTIVE_ARTIST_PLAYS) {
                $this->logger->info(
                    'DMCA Compliance: Song rejected Ã¢â‚¬â€ too many consecutive plays by same artist.',
                    [
                        'title'       => $entry->title,
                        'artist'      => $artist,
                        'consecutive' => $consecutiveArtistCount,
                        'limit'       => self::MAX_CONSECUTIVE_ARTIST_PLAYS,
                    ]
                );
                return false;
            }
        }

        // All rules passed.
        return true;
    }
}
