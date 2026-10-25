<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Resolves the same native strict playlist windows that Liquidsoap gives
 * wall-clock authority in RigidScheduleRuntimeConfiguration.
 *
 * The runtime intentionally plays Strict / Exact Time schedules from a dedicated
 * native Liquidsoap source instead of the ordinary PHP AutoDJ queue. Reporting
 * surfaces must therefore consult these windows or they will incorrectly show
 * the underlay AutoDJ queue as the next/on-air programme.
 */
final class RigidScheduleWindowResolver
{
    /**
     * @return array{playlist: StationPlaylist, schedule: StationSchedule, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function getActiveWindow(Station $station, DateTimeImmutable $at): ?array
    {
        foreach ($this->getWindows($station, $at, $at->modify('+1 second')) as $window) {
            if ($window['start']->getTimestamp() <= $at->getTimestamp()
                && $window['end']->getTimestamp() > $at->getTimestamp()) {
                return $window;
            }
        }

        return null;
    }

    /**
     * @return list<array{playlist: StationPlaylist, schedule: StationSchedule, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function getWindows(
        Station $station,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
    ): array {
        if ($rangeEnd <= $rangeStart) {
            return [];
        }

        $timezone = $station->getTimezoneObject();
        $start = CarbonImmutable::instance($rangeStart)->setTimezone($timezone);
        $end = CarbonImmutable::instance($rangeEnd)->setTimezone($timezone);
        $windows = [];

        // Preserve station/playlist iteration order so overlapping schedules are
        // resolved in the same order as the Liquidsoap strict switch branches.
        foreach ($station->playlists as $playlist) {
            if (!$this->isRuntimeEligiblePlaylist($playlist)) {
                continue;
            }

            foreach ($playlist->schedule_items as $schedule) {
                if (!$this->isRigidSchedule($schedule, $playlist)) {
                    continue;
                }

                $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                    $schedule,
                    $timezone,
                    $start,
                    $end,
                    1000,
                );

                foreach ($occurrences as $occurrence) {
                    // Half-open interval: a programme ending at 18:00 is no
                    // longer authoritative at exactly 18:00.
                    if ($occurrence->end <= $start || $occurrence->start >= $end) {
                        continue;
                    }

                    $windows[] = [
                        'playlist' => $playlist,
                        'schedule' => $schedule,
                        'start' => $occurrence->start,
                        'end' => $occurrence->end,
                    ];
                }
            }
        }

        usort(
            $windows,
            static fn(array $a, array $b): int => $a['start'] <=> $b['start'],
        );

        return $windows;
    }

    /**
     * True when this schedule row is aired by the native strict lane
     * (RigidScheduleRuntimeConfiguration) rather than by the PHP AutoDJ.
     *
     * The one rule both sides share: the strict lane plays exactly these rows,
     * and the AutoDJ never selects them. When both could pick the same
     * programme, it was queued twice and aired on top of itself.
     */
    public static function isAiredByStrictLane(StationPlaylist $playlist, StationSchedule $schedule): bool
    {
        if (!$playlist->is_enabled || count($playlist->group_memberships) > 0) {
            return false;
        }

        $hasNativeSource = PlaylistSources::Songs === $playlist->source
            || (PlaylistSources::RemoteUrl === $playlist->source && null !== $playlist->remote_url);

        return $hasNativeSource && ($schedule->strict_start || $schedule->is_emergency);
    }

    /**
     * How many tracks the strict lane plays in one window before handing the air
     * back to the AutoDJ, or null when it holds the whole window.
     *
     * The native source loops its M3U (mode="normal") while its window is open.
     * A "play once" or "play single track" programme therefore needs a limit, or
     * it restarts as soon as it finishes and is cut mid-episode when the window
     * closes. The Liquidsoap gate and the forecast both use this value.
     */
    public static function maxTracksPerWindow(StationPlaylist $playlist, StationSchedule $schedule): ?int
    {
        if (PlaylistSources::Songs !== $playlist->source) {
            return null;
        }

        if ($playlist->backendPlaySingleTrack()) {
            return 1;
        }

        if ($schedule->loop_once) {
            return max(1, $playlist->media_items->count());
        }

        return null;
    }

    private function isRuntimeEligiblePlaylist(StationPlaylist $playlist): bool
    {
        if (!$playlist->is_enabled) {
            return false;
        }

        // Match RigidScheduleRuntimeConfiguration: these are PHP-queue-backed
        // sources and cannot be represented by an independent native source.
        if (in_array($playlist->source, [PlaylistSources::Playlists, PlaylistSources::Requests], true)) {
            return false;
        }

        if (count($playlist->group_memberships) > 0) {
            return false;
        }

        if (PlaylistSources::RemoteUrl === $playlist->source && null === $playlist->remote_url) {
            return false;
        }

        return in_array($playlist->source, [PlaylistSources::Songs, PlaylistSources::RemoteUrl], true);
    }

    private function isRigidSchedule(StationSchedule $schedule, StationPlaylist $playlist): bool
    {
        // Remote URL streams always get an exclusive Liquidsoap switch arm
        // (schedule_switch_remote_url) regardless of strict_start, so the
        // linear log must treat them as rigid to show correct times and
        // prevent bleeding into adjacent scheduled playlists.
        if (PlaylistSources::RemoteUrl === $playlist->source) {
            return true;
        }

        return $schedule->strict_start || $schedule->is_emergency;
    }
}
