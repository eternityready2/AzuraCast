<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bounded timing for ordinary flexible playlist schedules.
 *
 * The normal flexible path remains flexible. BroadcastClockPlanner and
 * QueueBuilder still aim at the nominal wall-clock boundary first: they backtime
 * ordinary music, prefer a track that fits the remaining window, preserve
 * crossfade math and let the existing Stretch/Squeeze metadata close small
 * timing gaps.
 *
 * This subscriber adds only the missing runtime backstop for audio that has
 * already been handed to Liquidsoap and therefore cannot be shortened later by
 * PHP queue planning. At nominal start + 60 seconds it checks the final on-air
 * metadata. If the scheduled playlist is already on air, nothing happens. If a
 * different AutoDJ queue row is still on air, it performs the same one-shot
 * clean AutoDJ cut used by the proven Top-of-Hour/rigid paths. The existing
 * schedule source then takes over through AzuraCast's normal graph.
 *
 * This deliberately does NOT create another playlist source or another switch:
 * that preserves sequential/shuffle state, remote-stream connections, playlist
 * groups, request playlists, crossfade, Stretch/Squeeze and the nested-clock fix
 * from the rigid scheduling work.
 *
 * Strict starts, emergency/interrupting schedules and live DJs remain outside
 * this policy. Clock Wheels retain their own flexible/strict scheduler and TOH
 * retains exact wall-clock authority.
 */
final class FlexibleScheduleRuntimeConfiguration implements EventSubscriberInterface
{
    /**
     * Conservative broadcast default: flexible may breathe, but never by minutes.
     * Kept in one place so it can become a station setting without changing the
     * scheduling/runtime contract.
     */
    public const int FLEXIBLE_GRACE_SECONDS = 60;

    public static function getSubscribedEvents(): array
    {
        // Run after ConfigWriter (playlists/crossfade/live), rigid (16) and TOH
        // (15). We do not wrap `radio`; we only observe the final on-air metadata
        // and, when necessary, advance the shared AutoDJ transport once.
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 14],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $checks = [];

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            foreach ($playlist->schedule_items as $schedule) {
                if (!$this->isFlexibleSchedule($playlist, $schedule)) {
                    continue;
                }

                $deadlineWindow = $this->getDeadlineWindow($event, $schedule);
                if ('false' === $deadlineWindow) {
                    continue;
                }

                $playlistId = isset($playlist->id) ? $playlist->id : spl_object_id($playlist);
                $scheduleKey = isset($schedule->id) ? $schedule->id : spl_object_id($schedule);
                $triggeredRef = 'bounded_flexible_' . $scheduleKey . '_triggered';
                $checkName = 'bounded_flexible_' . $scheduleKey . '_check';

                $event->appendLines([
                    $triggeredRef . ' = ref(false)',
                    'def ' . $checkName . '() =',
                    '    in_deadline_window = (' . $deadlineWindow . ')',
                    '',
                    '    if in_deadline_window then',
                    '        if not ' . $triggeredRef . '() then',
                    '            ' . $triggeredRef . ' := true',
                    '',
                    '            # Never take the microphone away from a live DJ.',
                    '            if not azuracast.live_enabled() then',
                    '                target_playlist = ' . ConfigWriter::toRawString((string)$playlistId),
                    '                current_playlist = bounded_flexible_current_playlist_id()',
                    '                current_sq = bounded_flexible_current_sq_id()',
                    '',
                    '                if current_playlist == target_playlist then',
                    '                    log(' . ConfigWriter::toRawString(
                        'Bounded Flexible: "' . $playlist->name
                        . '" reached air naturally inside the grace window.'
                    ) . ')',
                    '                elsif current_sq != "" then',
                    '                    # Only cut a real AutoDJ queue request. Native playlists,',
                    '                    # remote streams, rigid/TOH lanes and other non-queue sources',
                    '                    # are deliberately never skipped by this watchdog.',
                    '                    azuracast.discard_autodj_current_cleanly()',
                    '                    log(' . ConfigWriter::toRawString(
                        'Bounded Flexible: 60-second grace deadline reached for "'
                        . $playlist->name . '"; cleanly advancing the late AutoDJ row.'
                    ) . ')',
                    '                end',
                    '            end',
                    '        end',
                    '    else',
                    '        # Re-arm after this one-minute deadline window so the next',
                    '        # daily/weekly/recurring occurrence can enforce independently.',
                    '        ' . $triggeredRef . ' := false',
                    '    end',
                    'end',
                    '',
                ]);

                $checks[] = $checkName . '()';
            }
        }

        if ([] === $checks) {
            return;
        }

        $event->appendBlock(
            <<<'LIQ'
            # Bounded flexible scheduling observes the FINAL station graph. Both
            # native playlist files and AutoDJ queue annotations can carry a
            # playlist_id; sq_id specifically identifies a PHP AutoDJ queue row.
            bounded_flexible_current_playlist_id = ref("")
            bounded_flexible_current_sq_id = ref("")

            def bounded_flexible_capture_metadata(m) =
                bounded_flexible_current_playlist_id := list.assoc(default="", "playlist_id", m)
                bounded_flexible_current_sq_id := list.assoc(default="", "sq_id", m)
            end

            source.methods(radio).on_metadata(
                synchronous=false,
                bounded_flexible_capture_metadata
            )
            LIQ
        );

        $body = implode("\n    ", $checks);
        $event->appendBlock(
            <<<LIQ
            # Poll four times per second so the one-minute deadline predicate is
            # reliable without adding another audio source, switch or clock.
            thread.run.recurrent(delay=0.25, {
                {$body}
            })
            LIQ
        );
    }

    private function isFlexibleSchedule(
        StationPlaylist $playlist,
        StationSchedule $schedule,
    ): bool {
        return !$schedule->strict_start
            && !$schedule->is_emergency
            && !$playlist->backendInterruptOtherSongs();
    }

    /**
     * Build a one-minute station-local deadline window beginning exactly at
     * nominal start + grace. A ref prevents more than one action per occurrence.
     */
    private function getDeadlineWindow(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $schedule,
    ): string {
        $tz = $event->getStation()->getTimezoneObject();

        if (ScheduleRecurrence::hasRecurrence($schedule)) {
            $now = CarbonImmutable::now($tz);
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $now->subDay(),
                $now->addDays(400),
                500,
            );

            $parts = [];
            foreach ($occurrences as $occurrence) {
                $deadline = CarbonImmutable::instance($occurrence->start)
                    ->setTimezone($tz)
                    ->addSeconds(self::FLEXIBLE_GRACE_SECONDS);
                $windowEnd = $deadline->addSeconds(59);

                $parts[] = '(time() >= ' . $deadline->getTimestamp()
                    . '. and time() <= ' . $windowEnd->getTimestamp() . '.)';
            }

            if ([] === $parts) {
                return 'false';
            }

            $key = isset($schedule->id) ? $schedule->id : spl_object_id($schedule);
            $method = 'bounded_flexible_' . $key . '_deadline_recurrence';
            $event->appendLines([
                'def ' . $method . '() =',
                '    (' . implode(' or ', $parts) . ')',
                'end',
            ]);

            return $method . '()';
        }

        [$deadlineSeconds, $dayOffset] = $this->shiftStartByGrace($schedule->start_time);
        $windowEndSeconds = $deadlineSeconds + 59;
        $windowEndDayOffset = $dayOffset + intdiv($windowEndSeconds, 86400);
        $windowEndSeconds %= 86400;

        $days = $this->shiftDays($schedule->days, $dayOffset);
        $endDays = $this->shiftDays($schedule->days, $windowEndDayOffset);

        if ($dayOffset === $windowEndDayOffset) {
            $playTime = $this->formatSecondsCode($deadlineSeconds)
                . '-' . $this->formatSecondsCode($windowEndSeconds);

            if ([] !== $days && count($days) < 7) {
                $playTime = '(' . $this->formatDays($days) . ') and ' . $playTime;
            }
        } else {
            $first = $this->formatSecondsCode($deadlineSeconds) . '-23h59m59s';
            $second = '00h00m00s-' . $this->formatSecondsCode($windowEndSeconds);

            if ([] !== $days && count($days) < 7) {
                $first = '(' . $this->formatDays($days) . ') and ' . $first;
                $second = '(' . $this->formatDays($endDays) . ') and ' . $second;
            }

            $playTime = '(' . $first . ') or (' . $second . ')';
        }

        return $this->applyScheduleDateRangeBounds($event, $schedule, $playTime);
    }

    /** @return array{int, int} seconds of day, day offset */
    private function shiftStartByGrace(int $timeCode): array
    {
        $seconds = $this->timeCodeToSeconds($timeCode) + self::FLEXIBLE_GRACE_SECONDS;
        $dayOffset = intdiv($seconds, 86400);

        return [$seconds % 86400, $dayOffset];
    }

    private function timeCodeToSeconds(int $timeCode): int
    {
        $padded = str_pad((string)$timeCode, 4, '0', STR_PAD_LEFT);

        return ((int)substr($padded, 0, 2) * 3600)
            + ((int)substr($padded, 2, 2) * 60);
    }

    private function formatSecondsCode(int $seconds): string
    {
        $hour = intdiv($seconds, 3600);
        $minute = intdiv($seconds % 3600, 60);
        $second = $seconds % 60;

        return $hour . 'h' . $minute . 'm' . $second . 's';
    }

    /** @param int[] $days @return int[] */
    private function shiftDays(array $days, int $offset): array
    {
        if ([] === $days || 0 === $offset) {
            return $days;
        }

        return array_map(
            static fn(int $day): int => (($day - 1 + $offset) % 7) + 1,
            $days,
        );
    }

    /** @param int[] $days */
    private function formatDays(array $days): string
    {
        return implode(
            ' or ',
            array_map(
                static fn(int $day): string => (($day === 7) ? '0' : $day) . 'w',
                $days,
            ),
        );
    }

    private function applyScheduleDateRangeBounds(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $schedule,
        string $playTime,
    ): string {
        $startDate = $schedule->start_date;
        $endDate = $schedule->end_date;

        if (empty($startDate) && empty($endDate)) {
            return $playTime;
        }

        $tz = $event->getStation()->getTimezoneObject();
        $key = isset($schedule->id) ? $schedule->id : spl_object_id($schedule);
        $method = 'bounded_flexible_' . $key . '_deadline_date_range';
        $body = ['def ' . $method . '() ='];
        $conditions = [];

        if (!empty($startDate)) {
            $startDateObj = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tz);
            if (null !== $startDateObj) {
                $body[] = '    range_start = ' . $startDateObj->setTime(0, 0)->getTimestamp() . '.';
                $conditions[] = 'range_start <= current_time';
            }
        }

        if (!empty($endDate)) {
            $endDateObj = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $tz);
            if (null !== $endDateObj) {
                // A deadline can cross midnight by one grace minute. Keep that
                // final occurrence eligible without broadening later days.
                $rangeEnd = $endDateObj->setTime(23, 59, 59)
                    ->addSeconds(self::FLEXIBLE_GRACE_SECONDS + 59);
                $body[] = '    range_end = ' . $rangeEnd->getTimestamp() . '.';
                $conditions[] = 'current_time <= range_end';
            }
        }

        if ([] === $conditions) {
            return $playTime;
        }

        $body[] = '    current_time = time()';
        $body[] = '    result = (' . implode(' and ', $conditions) . ')';
        $body[] = '    result';
        $body[] = 'end';
        $event->appendLines($body);

        return $method . '() and (' . $playTime . ')';
    }
}
