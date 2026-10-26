<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Service\AiDjMusicBed;
use App\Utilities\Time;
use Psr\SimpleCache\CacheInterface;

/**
 * AI DJ speech handed to Liquidsoap that has not reached air yet.
 *
 * A DJ clip goes straight to Liquidsoap's AI DJ queue and airs at the next
 * song change. Its queue row is written already marked played and without a
 * length, so the hand-off's count of what airs ahead of a row left it out, and
 * the Top-of-Hour swap timed the hour's last song from a start that was early
 * by the clip's length. Sun 2026-10-11: an 18s "Song Commentary + Joke" waited
 * ahead of "No One Like The Lord"; Wayne Watson's "Friend of a Wounded Heart"
 * (5:18) was judged to start at 12:54:40 and land on the ID, started at
 * 12:54:58, and the 12:59:59 ID cut 8s of it.
 */
final class PendingAiDjSpeech
{
    use EntityManagerAwareTrait;

    /** The queue page's limit for a clip still waiting (QueueController). */
    private const int STALE_SECONDS = 900;

    /**
     * A clip airs at the next song change, or at the one after when the next
     * song was already loaded. Once two items have started since it was
     * queued, neither of them the clip, it is not waiting any more.
     */
    private const int ITEMS_STARTED_WHEN_STALE = 2;

    private const int LENGTH_CACHE_SECONDS = 3600;

    public function __construct(
        private readonly AiDjMusicBed $audioFiles,
        private readonly CacheInterface $cache,
    ) {
    }

    /** Seconds of AI DJ speech waiting in Liquidsoap, which airs before anything AutoDJ sends next. */
    public function secondsWaiting(Station $station): float
    {
        /** @var StationQueue[] $clips */
        $clips = $this->em->createQuery(
            <<<'DQL'
                SELECT sq FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played IS NULL
                AND sq.media IS NULL
                AND sq.autodj_custom_uri LIKE :aiDjPath
                AND sq.timestamp_cued >= :since
            DQL
        )->setParameter('station', $station)
            ->setParameter('aiDjPath', '%/ai_dj/%')
            ->setParameter('since', Time::nowUtc()->subSeconds(self::STALE_SECONDS))
            ->getResult();

        $seconds = 0.0;
        foreach ($clips as $clip) {
            if ($this->isWaiting($station, $clip)) {
                $seconds += $this->lengthOf((string)$clip->autodj_custom_uri);
            }
        }

        return $seconds;
    }

    private function isWaiting(Station $station, StationQueue $clip): bool
    {
        /** @var string[] $startedSince */
        $startedSince = $this->em->createQuery(
            <<<'DQL'
                SELECT sh.song_id FROM App\Entity\SongHistory sh
                WHERE sh.station = :station
                AND sh.timestamp_start >= :cued
            DQL
        )->setParameter('station', $station)
            ->setParameter('cued', $clip->timestamp_cued)
            ->getSingleColumnResult();

        return !in_array($clip->song_id, $startedSince, true)
            && count($startedSince) < self::ITEMS_STARTED_WHEN_STALE;
    }

    private function lengthOf(string $path): float
    {
        $key = 'ai_dj_clip_length_' . md5($path);
        $cached = $this->cache->get($key);
        if (is_numeric($cached)) {
            return (float)$cached;
        }

        $length = $this->audioFiles->duration($path);
        if ($length > 0.0) {
            $this->cache->set($key, $length, self::LENGTH_CACHE_SECONDS);
        }

        return $length;
    }
}
