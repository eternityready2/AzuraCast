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
                if (!$this->isRigidSchedule($schedule)) {
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

    private function isRigidSchedule(StationSchedule $schedule): bool
    {
        // Playlist-wide Start Behavior remains an ordinary/flexible behavior.
        // Only an explicit Strict / Exact Time row (or emergency row) gets the
        // outer native wall-clock lane.
        return $schedule->strict_start || $schedule->is_emergency;
    }
}
