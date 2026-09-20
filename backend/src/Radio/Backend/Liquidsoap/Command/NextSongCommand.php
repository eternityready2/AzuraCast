<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use App\Entity\Station;
use App\Radio\AutoDJ\Annotations;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Hands Liquidsoap the next AutoDJ track -- except while the Top-of-Hour
 * Station ID owns the air.
 *
 * WHY THIS REFUSES DURING THE ID WINDOW
 *
 * While the ID plays, the processed station underlay is deliberately kept
 * clocked at zero gain so the inner crossfade operator can consume the
 * release-time clean cut. "Still clocked" also means that the moment the
 * interrupted song ends, the underlay fetches the NEXT request and plays it
 * silently underneath the ID -- and that track is then discarded at :00. One
 * track burned per hour, inaudible but gone from rotation, and the new hour
 * opens on the track after it instead of the one that was queued.
 *
 * That burn gets worse, not better, once duration-matched swapping is landing
 * songs exactly on the deadline, because then the underlay runs dry at :59:ss
 * every single hour rather than occasionally.
 *
 * Liquidsoap's request.dynamic treats any non-200 from this endpoint as "no
 * request available" (see azuracast.api_call: it returns null on a non-200, and
 * azuracast.autodj_next_song passes that straight through). So refusing here is
 * the documented way to tell the transport to stay dry. The TOH lane drains the
 * transport's one reserved request at takeover, this endpoint declines to refill
 * it, and at release the lane calls prefetch_autodj_next() to fetch immediately
 * -- so the new hour opens on a fresh track from 0:00 with no waiting and
 * nothing wasted.
 *
 * The refusal window is only the ~39 seconds between the ID deadline and :00,
 * and only when TOH is enabled for the station. Queue building is untouched:
 * the StationQueue rows still exist, they are simply not handed out yet.
 */
final class NextSongCommand extends AbstractCommand
{
    public function __construct(
        private readonly Annotations $annotations,
        private readonly TopOfHourClock $topOfHourClock,
    ) {
    }

    protected function doRun(
        Station $station,
        bool $asAutoDj = false,
        array $payload = []
    ): array {
        if ($this->isInsideTopOfHourIdWindow($station)) {
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
     * True between the ID's exact :59:ss deadline and the following :00.
     */
    private function isInsideTopOfHourIdWindow(Station $station): bool
    {
        if (!$this->topOfHourClock->isEnabled($station)) {
            return false;
        }

        $now = CarbonImmutable::now($station->getTimezoneObject());

        $boundary = CarbonImmutable::instance(
            $this->topOfHourClock->getNextBoundary($station, $now->toDateTimeImmutable())
        );

        $target = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->topOfHourClock->getIdStartSecond($station));

        return $now >= $target && $now < $boundary;
    }
}
