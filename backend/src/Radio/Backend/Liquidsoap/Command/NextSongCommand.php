<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use App\Cache\AutoCueCache;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\Annotations;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Backend\Liquidsoap;
use Carbon\CarbonImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

/**
 * Hands Liquidsoap the next AutoDJ track -- except while the Top-of-Hour
 * Station ID owns the air.
 *
 * WHY THIS REFUSES DURING THE ID WINDOW
 *
 * While the ID plays, the processed station underlay is deliberately kept
 * clocked at zero gain so the inner crossfade operator can consume the
 * release-time clean cut. "Still clocked" also means that the moment the
 * interrupted song ends, the underlay would otherwise fetch the NEXT request
 * and play it silently underneath the ID -- surfacing mid-song once the ID
 * releases. The TOH lane prevents that by draining the transport's one
 * reserved request at takeover (see
 * TopOfHourRuntimeConfiguration::top_of_hour_id_enter), and this endpoint
 * declines to refill it for the rest of the window.
 *
 * That reserved request's StationQueue row was already marked "sent" the
 * moment it was originally resolved, though -- so discarding it in Liquidsoap
 * would otherwise lose it from rotation for good, and the new hour would open
 * on the row queued AFTER it instead of the one that was actually queued next.
 * releasePendingAutoDjReserve() below gives it back the moment the window
 * opens, so at release the lane's prefetch_autodj_next() call resolves that
 * exact same row fresh from 0:00 -- nothing is silently skipped.
 *
 * Liquidsoap's request.dynamic treats any non-200 from this endpoint as "no
 * request available" (see azuracast.api_call: it returns null on a non-200, and
 * azuracast.autodj_next_song passes that straight through). So refusing here is
 * the documented way to tell the transport to stay dry.
 *
 * The refusal window is normally the ~39 seconds between the ID deadline and
 * :00, and only when TOH is enabled for the station. Queue building is
 * untouched: the StationQueue rows still exist, they are simply not handed
 * out yet.
 *
 * PAST :00: an AI news bulletin at the top of the hour can genuinely still be
 * on air after :00 ("ran long") -- top_of_hour_id_should_play() in the
 * Liquidsoap runtime keeps the ID/news lane active for exactly that case.
 * Refusing only up to a fixed :00 cutoff, with no way to tell whether the
 * lane has actually released yet, was itself a bug: once wall-clock passed
 * :00 this endpoint resumed handing out real tracks while the lane was still
 * silently holding the air, so a track got resolved fresh, sat unheard, and
 * then had to race whatever the lane kept at release -- audible as a song
 * starting after the ID and then being cut for a different one seconds
 * later. Past :00, isStillHeldByLiquidsoap() asks the runtime's own
 * top_of_hour_id_control.active state directly instead of guessing a fixed
 * pad: a pad shorter than the real overrun reproduces this exact race, and a
 * pad longer than it strands the underlay dry, refusing every legitimate
 * post-release request until the pad expires.
 */
final class NextSongCommand extends AbstractCommand
{
    /**
     * Absolute safety cap on how long past the plain hour boundary this will
     * keep refusing requests while waiting on Liquidsoap's live "still
     * holding" signal (see isStillHeldByLiquidsoap()). Guards against a lost
     * or wedged signal ever holding the AutoDJ transport dry indefinitely --
     * after this many seconds past :00, requests are allowed through
     * regardless of what Liquidsoap reports.
     */
    private const int MAX_OVERRUN_GRACE_SECONDS = 300;

    private const int PRE_ID_REFUSAL_SECONDS = 10;

    public function __construct(
        private readonly Annotations $annotations,
        private readonly TopOfHourClock $topOfHourClock,
        private readonly Adapters $adapters,
        private readonly StationQueueRepository $queueRepo,
        private readonly AutoCueCache $autoCueCache,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CacheInterface $cache,
    ) {
    }

    protected function doRun(
        Station $station,
        bool $asAutoDj = false,
        array $payload = []
    ): array {
        if ($this->isInsideTopOfHourIdWindow($station)) {
            $this->warmNextHourOpeners($station);

            // The Liquidsoap runtime discards whatever it already had resolved
            // one-ahead so that request cannot surface mid-song once the ID
            // releases (see TopOfHourRuntimeConfiguration::top_of_hour_id_enter).
            // That request's StationQueue row was already marked "sent" the
            // moment it was resolved, though, so without this it would be lost
            // from rotation for good. Give it back every time a request is
            // refused in this window; idempotent once nothing is left to give
            // back.
            $released = $this->topOfHourClock->releasePendingAutoDjReserve($station);

            // The ID window is a ~39s stretch in which nothing else about the
            // AutoDJ queue is observable, so the outcome is logged either way.
            // Otherwise a deliberate no-op here and this endpoint never being
            // reached at all look identical after the fact, which makes any
            // report of "the wrong song opened the hour" undiagnosable.
            if (null !== $released) {
                $this->logger->notice(
                    'Top-of-Hour ID: handed the unaired AutoDJ reserve back to the queue.',
                    [
                        'queue_id' => $released->id,
                        'song' => $released->text,
                        'duration' => $released->duration,
                    ]
                );
            } else {
                $this->logger->info(
                    'Top-of-Hour ID: no unaired AutoDJ reserve to hand back this time.'
                );
            }

            // Non-200 -> Liquidsoap reads this as "no request available".
            throw new RuntimeException(
                'Top-of-Hour Station ID owns the air until :00; '
                . 'holding the AutoDJ transport dry so no track is consumed under the ID.'
            );
        }

        return [
            'uri' => $this->annotations->annotateNextSong($station, $asAutoDj),
        ];
    }

    /**
     * True from the ID's exact :59:ss deadline through however long the
     * Liquidsoap runtime is actually still holding the air for it -- normally
     * the following :00, but see the class doc for why that is not always so.
     */
    private function isInsideTopOfHourIdWindow(Station $station): bool
    {
        if (!$this->topOfHourClock->isEnabled($station)) {
            return false;
        }

        $now = CarbonImmutable::now($station->getTimezoneObject());

        // Uses the shared TopOfHourClock::getTargetStartFor() rather than
        // recomputing the target locally, so this can never drift out of
        // sync with the same calculation the swap selector and queue
        // constraint use. Target always precedes the next boundary, so
        // target..boundary is simply "now >= target".
        $target = CarbonImmutable::instance(
            $this->topOfHourClock->getTargetStartFor($station, $now->toDateTimeImmutable())
        );

        // Also refuse just before the ID: a track loaded now could only air a few
        // seconds before being cut, and one that is still loaded when the ID takes
        // over plays muted underneath it (2026-09-23 2:59:58 -> silent under ID).
        if ($now >= $target->subSeconds(self::PRE_ID_REFUSAL_SECONDS)) {
            return true;
        }

        // Just past the boundary the ID (and any AI News) can still own the air.
        // getNextBoundary() from now already points at the NEXT hour here, so
        // measure from the boundary that just passed; otherwise this grey zone
        // is unreachable and songs start muted under the ID/news.
        $lastBoundary = CarbonImmutable::instance(
            $this->topOfHourClock->getNextBoundary($station, $now->subHour()->toDateTimeImmutable())
        );

        if (abs($now->diffInSeconds($lastBoundary)) > self::MAX_OVERRUN_GRACE_SECONDS) {
            // Absolute safety cap -- never refuse forever on a lost/wedged signal.
            return false;
        }

        return $this->isStillHeldByLiquidsoap($station);
    }

    /**
     * Synchronously asks the Liquidsoap runtime (via the same telnet bridge
     * StageTopOfHourStationIdTask already uses to push target/boundary
     * epochs) whether the ID/news lane is still actively holding the air
     * right now. Any failure -- adapter mismatch, telnet unreachable,
     * unexpected response -- falls back to "no longer held", i.e. the
     * pre-existing fixed-:00-cutoff behavior, so a broken signal degrades to
     * the previous bug rather than to indefinite dead air.
     */
    private function isStillHeldByLiquidsoap(Station $station): bool
    {
        try {
            $backend = $this->adapters->getBackendAdapter($station);
            if (!$backend instanceof Liquidsoap) {
                return false;
            }

            $response = $backend->command($station, 'top_of_hour_id_control.active');

            return 'true' === trim($response[0] ?? '');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Top-of-Hour ID: could not confirm live lane state past :00; '
                . 'treating the window as closed.',
                ['exception' => $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * While the ID holds the air, pre-compute AutoCue for the items queued to
     * open the hour, so the first one starts the instant the lane releases
     * instead of after several seconds of on-the-fly analysis.
     */
    private function warmNextHourOpeners(Station $station): void
    {
        try {
            $backend = $this->adapters->getBackendAdapter($station);
            if (!$backend instanceof Liquidsoap) {
                return;
            }

            foreach ($this->queueRepo->getNextToSendToAutoDjRows($station, 2) as $row) {
                $media = $row->media;
                if (null === $media) {
                    continue;
                }
                if (null !== $this->autoCueCache->getForCacheKey($this->autoCueCache->getCacheKey($media))) {
                    continue;
                }

                $flag = 'toh_autocue_warm_' . $row->id;
                if ($this->cache->has($flag)) {
                    continue;
                }
                $this->cache->set($flag, true, 300);

                $event = AnnotateNextSong::fromStationQueue($row, false);
                $this->dispatcher->dispatch($event);

                $backend->command($station, 'autocue_warm ' . $event->buildAnnotations());
                $this->logger->info(
                    'Top-of-Hour ID: pre-analysing the next item so the hour opens without delay.',
                    ['queue_id' => $row->id, 'song' => $row->text]
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning('Top-of-Hour ID: AutoCue warm-up failed.', ['exception' => $e->getMessage()]);
        }
    }
}
