<?php

declare(strict_types=1);

namespace App\Service\Ads;

use App\Entity\AdOrder;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\QueueBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the hidden sponsor playlist behind an ad order in step with the order itself.
 *
 * Selling airtime and delivering it are two different jobs. The order screen is the
 * selling half; this is the delivery half. Approving an order creates (or revives) a
 * sponsor playlist carrying the spot, with the contracted plays-per-day and dates set
 * on it, which is what {@see \App\Radio\AutoDJ\SponsorGuaranteedPlayoutService} already
 * watches to force catch-up plays. Pausing, ending or declining the order switches that
 * playlist off without destroying the booking.
 *
 * Operators never see or edit these playlists; the order is the source of truth and this
 * rewrites the playlist from it every save.
 */
final class AdOrderPlayoutSync
{
    /** Prefix that marks a playlist as owned by an ad order, so it can be told apart in lists. */
    public const string PLAYLIST_PREFIX = 'Ad: ';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationPlaylistMediaRepository $spmRepo,
    ) {
    }

    /**
     * Push the order's current state onto its playlist, creating one if needed.
     * Safe to call on every save.
     */
    public function sync(AdOrder $order): void
    {
        // A live read has no audio to schedule: the host reads it from the order screen.
        // Nothing to put in rotation, so tear down any playlist the order used to own.
        if ($order->is_live_read) {
            $this->removePlaylist($order);
            return;
        }

        if (null === $order->media) {
            // Sold but no creative delivered yet. Don't put an empty playlist on the air.
            $this->removePlaylist($order);
            return;
        }

        $playlist = $order->playlist ?? $this->createPlaylist($order);
        $spotsPerDay = $order->getEffectiveSpotsPerDay();

        $playlist->name = self::PLAYLIST_PREFIX . $order->name;
        $playlist->is_sponsor = true;
        $playlist->sponsor_name = $order->advertiser->name;
        $playlist->sponsor_guaranteed_plays_per_day = $spotsPerDay ?: null;
        $playlist->sponsor_contract_start = $order->getStartsAtDateTime();
        $playlist->sponsor_contract_end = $order->getEndsAtDateTime();

        // "8 a day" means 8 spread across the day, not 8 at random. Once-per-X-minutes
        // spaces the plays evenly (8/day = one every 3 hours), and the sponsor guarantee
        // forces catch-up plays when a live show or strict block squeezed some out.
        $playlist->type = PlaylistTypes::OncePerXMinutes;
        $playlist->play_per_minutes = $spotsPerDay > 0 ? max(15, intdiv(1440, $spotsPerDay)) : 0;

        // Same treatment as the station's own promo playlists: jingle mode keeps the
        // spot out of "now playing" metadata, and the top-of-hour fit logic skips
        // jingle-mode tracks, so a paid spot is never tempo-stretched or squeezed.
        $playlist->is_jingle = true;

        // Only an approved order inside its dates, with a daily count, makes noise.
        $playlist->is_enabled = $order->isOnAir() && $spotsPerDay > 0;

        $this->em->persist($playlist);
        $order->playlist = $playlist;
        $this->em->persist($order);
        $this->em->flush();

        $this->syncMedia($order, $playlist);
    }

    /**
     * Make the playlist hold exactly one file: this order's spot. Orders are swapped to new
     * creative mid-flight often enough that rewriting is safer than diffing.
     */
    private function syncMedia(AdOrder $order, StationPlaylist $playlist): void
    {
        if (null === $order->media) {
            return;
        }

        if ($this->spmRepo->isMediaInPlaylist($order->media, $playlist)) {
            $inPlaylist = $this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(spm.id) FROM App\Entity\StationPlaylistMedia spm
                    WHERE spm.playlist = :playlist
                DQL
            )->setParameter('playlist', $playlist)->getSingleScalarResult();

            if (1 === (int)$inPlaylist) {
                return;
            }
        }

        $this->spmRepo->emptyPlaylist($playlist);
        $this->spmRepo->addMediaToPlaylist($order->media, $playlist);
        $this->em->flush();
    }

    /**
     * Build the playlist that carries this order. Sequential order with a single track keeps
     * the log readable, and requests are off because an ad is not a song anyone asks for.
     */
    private function createPlaylist(AdOrder $order): StationPlaylist
    {
        $playlist = new StationPlaylist($order->station);
        $playlist->source = PlaylistSources::Songs;
        $playlist->order = PlaylistOrders::Sequential;
        $playlist->include_in_requests = false;
        $playlist->include_in_on_demand = false;

        return $playlist;
    }

    /**
     * Drop the playlist an order used to own. Called when the order loses its creative,
     * becomes a live read, or is deleted.
     */
    public function removePlaylist(AdOrder $order): void
    {
        $playlist = $order->playlist;
        if (null === $playlist) {
            return;
        }

        $order->playlist = null;
        $this->em->persist($order);
        $this->em->flush();

        $this->em->remove($playlist);
        $this->em->flush();
    }
}
