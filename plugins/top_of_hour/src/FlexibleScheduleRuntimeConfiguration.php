<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Backend\Liquidsoap\PlaylistFileWriter;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives ordinary flexible playlist schedules a bounded amount of lateness.
 *
 * The existing BroadcastClockPlanner/QueueBuilder path remains the first line
 * of defence: it backtimes toward the nominal schedule boundary, selects a
 * track that fits when possible and lets Stretch/Squeeze close small gaps.
 * This runtime is only the deadline backstop for a track that is already on air
 * and therefore cannot be shortened retroactively by PHP queue planning.
 *
 * Flexible is intentionally still different from rigid/strict scheduling:
 *
 *   nominal start ---- natural handoff allowed ---- +60s deadline
 *
 * If the scheduled playlist is already on air before the deadline, this layer
 * does nothing. If it is still late at the deadline, it uses the same clean
 * AutoDJ cut primitive as the proven rigid/Top-of-Hour paths and takes over
 * with an isolated native source. Strict schedules, emergency schedules,
 * interrupting playlists, live DJs, Clock Wheels and Top-of-Hour are excluded
 * and keep their existing authorities.
 *
 * Only native Songs playlists are forced here. Remote streams are deliberately
 * left on their existing scheduler path: opening a second independent HTTP
 * input merely to enforce the grace deadline can reconnect a stream that is
 * already on air. Scheduler::getPlaylistScheduleDuration() already prevents a
 * late remote programme from cascading its lateness into following events.
 */
final class FlexibleScheduleRuntimeConfiguration implements EventSubscriberInterface
{
    public const int FLEXIBLE_GRACE_SECONDS = 60;

    public static function getSubscribedEvents(): array
    {
        // ConfigWriter has already created the normal station graph. This wrapper
        // sits outside crossfade/AutoDJ, but below rigid (16) and TOH (15):
        // TOH -> rigid -> bounded flexible -> live/AutoDJ.
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 17],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $branches = [];
        $branchData = [];

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            // Playlist Groups and Requests are resolved through PHP AutoDJ and do
            // not own an isolated native media source. Remote streams are excluded
            // for the reconnect-safety reason documented above.
            if (PlaylistSources::Songs !== $playlist->source) {
                continue;
            }

            if (count($playlist->group_memberships) > 0) {
                continue;
            }

            $flexibleSchedules = [];
            foreach ($playlist->schedule_items as $scheduleItem) {
                if ($this->isFlexibleSchedule($playlist, $scheduleItem)) {
                    $flexibleSchedules[] = $scheduleItem;
                }
            }

            if ([] === $flexibleSchedules) {
                continue;
            }

            $playlistId = isset($playlist->id) ? $playlist->id : spl_object_id($playlist);
            $playlistVarName = 'bounded_' . ConfigWriter::getPlaylistVariableName($playlist) . '_' . $playlistId;

            $this->writeDedicatedSource($event, $playlist, $playlistVarName);

            foreach ($flexibleSchedules as $scheduleItem) {
                $playTime = $this->getBoundedPlaylistPlayTime($event, $scheduleItem);
                if ('false' === $playTime) {
                    continue;
                }

                $scheduleKey = isset($scheduleItem->id)
                    ? $scheduleItem->id
                    : spl_object_id($scheduleItem);
                $stateName = 'bounded_flexible_' . $scheduleKey . '_active';
                $predicateName = 'bounded_flexible_' . $scheduleKey . '_should_play';
                $enterName = 'bounded_flexible_' . $scheduleKey . '_enter';

                $branchData[] = [
                    'playlist_id' => (string)$playlistId,
                    'playlist_name' => $playlist->name,
                    'source' => $playlistVarName,
                    'play_time' => $playTime,
                    'state' => $stateName,
                    'predicate' => $predicateName,
                    'enter' => $enterName,
                ];
            }
        }

        if ([] === $branchData) {
            return;
        }

        $event->appendBlock(
            <<<'LIQ'
            # Bounded flexible scheduling state.
            # Capture the playlist identity that is really on the underlying air
            # path. Static playlist files and AutoDJ annotations both carry this
            # field, so a schedule that made a natural handoff inside the grace
            # window will not be interrupted again at the deadline.
            bounded_flexible_current_playlist_id = ref("")

            def bounded_flexible_capture_metadata(m) =
                bounded_flexible_current_playlist_id := list.assoc(default="", "playlist_id", m)
            end

            radio_before_bounded_flexible = radio
            source.methods(radio_before_bounded_flexible).on_metadata(
                synchronous=false,
                bounded_flexible_capture_metadata
            )
            LIQ
        );

        $allStateNames = array_column($branchData, 'state');
        $clearStateLines = implode("\n                ", array_map(
            static fn(string $state): string => $state . ' := false',
            $allStateNames,
        ));

        foreach ($branchData as $data) {
            $event->appendLines([
                $data['state'] . ' = ref(false)',
                'def ' . $data['predicate'] . '() =',
                '    in_deadline_window = (' . $data['play_time'] . ')',
                '',
                '    if not in_deadline_window then',
                '        ' . $data['state'] . ' := false',
                '        false',
                '    elsif ' . $data['state'] . '() then',
                '        true',
                '    elsif azuracast.live_enabled() then',
                '        # Flexible automation never takes the microphone away from a live DJ.',
                '        false',
                '    else',
                '        bounded_flexible_current_playlist_id() != ' . ConfigWriter::toRawString($data['playlist_id']),
                '    end',
                'end',
                '',
                'def ' . $data['enter'] . '(_, new) =',
                '    ' . str_replace("\n                ", "\n    ", $clearStateLines),
                '    ' . $data['state'] . ' := true',
                '',
                '    if not azuracast.live_enabled() then',
                '        azuracast.discard_autodj_current_cleanly()',
                '        log(' . ConfigWriter::toRawString(
                    'Bounded Flexible: grace deadline reached for "' . $data['playlist_name']
                    . '"; cleanly advancing from late AutoDJ audio.'
                ) . ')',
                '    end',
                '',
                '    new',
                'end',
                '',
            ]);

            $branches[] = '({' . $data['predicate'] . '()}, ' . $data['source'] . ')';
        }

        $event->appendBlock(
            <<<LIQ
            def bounded_flexible_exit(_, new) =
                {$clearStateLines}
                new
            end
            LIQ
        );

        $transitions = implode(
            ', ',
            [...array_column($branchData, 'enter'), 'bounded_flexible_exit'],
        );
        $branches[] = '({true}, radio_before_bounded_flexible)';

        $event->appendLines([
            '# Bounded flexible schedule wall-clock deadline lane.',
            'radio = switch(',
            '    id="bounded_flexible_schedule_runtime",',
            '    track_sensitive=false,',
            '    replay_metadata=true,',
            '    transition_length=0.0,',
            '    transitions=[' . $transitions . '],',
            '    [',
            '        ' . implode(",\n        ", $branches),
            '    ]',
            ')',
        ]);
    }

    private function writeDedicatedSource(
        WriteLiquidsoapConfiguration $event,
        StationPlaylist $playlist,
        string $playlistVarName,
    ): void {
        $playlistMode = match ($playlist->order) {
            PlaylistOrders::Sequential => 'normal',
            PlaylistOrders::Shuffle, PlaylistOrders::SmartShuffle => 'randomize',
            PlaylistOrders::Random => 'random',
        };

        $playlistParams = [
            'id=' . ConfigWriter::toRawString($playlistVarName),
            'mime_type="audio/x-mpegurl"',
            'mode="' . $playlistMode . '"',
            'reload_mode="watch"',
            ConfigWriter::toRawString(PlaylistFileWriter::getPlaylistFilePath($playlist)),
        ];

        $event->appendLines([
            '# Dedicated native source for bounded flexible deadline enforcement.',
            $playlistVarName . ' = playlist(' . implode(',', $playlistParams) . ')',
        ]);

        if ($playlist->backendMerge()) {
            $event->appendLines([
                $playlistVarName . ' = merge_tracks(id="merge_' . $playlistVarName . '", ' . $playlistVarName . ')',
            ]);
        }

        if ($playlist->is_jingle) {
            $event->appendLines([
                $playlistVarName . ' = azuracast.utilities.drop_metadata(' . $playlistVarName . ')',
            ]);
        }
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
     * Return the schedule window that begins at nominal start + grace and ends
     * at the original schedule end. Ordinary weekly schedules are emitted as
     * native Liquidsoap time predicates; recurrence schedules use absolute epoch
     * ranges so DST/date recurrence behavior stays identical to the scheduler.
     */
    private function getBoundedPlaylistPlayTime(
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
                $start = CarbonImmutable::instance($occurrence->start)
                    ->setTimezone($tz)
                    ->addSeconds(self::FLEXIBLE_GRACE_SECONDS);
                $end = CarbonImmutable::instance($occurrence->end)->setTimezone($tz);

                if (!$start->isBefore($end)) {
                    continue;
                }

                $parts[] = '(time() >= ' . $start->getTimestamp()
                    . '. and time() <= ' . $end->getTimestamp() . '.)';
            }

            if ([] === $parts) {
                return 'false';
            }

            $method = 'bounded_flexible_' . (isset($schedule->id) ? $schedule->id : spl_object_id($schedule))
                . '_recurrence';
            $event->appendLines([
                'def ' . $method . '() =',
                '    (' . implode(' or ', $parts) . ')',
                'end',
            ]);

            return $method . '()';
        }

        [$startSeconds, $dayOffset] = $this->shiftStartByGrace($schedule->start_time);
        $endSeconds = $this->timeCodeToSeconds($schedule->end_time);

        // A play-once schedule is represented by equal start/end values. Keep a
        // one-minute predicate at the grace deadline; predicate.at_most behavior
        // in the underlying playlist still controls single-track schedules.
        if ($schedule->start_time === $schedule->end_time) {
            $playTime = $this->formatSecondsCode($startSeconds);
            $days = $this->shiftDays($schedule->days, $dayOffset);
            if ([] !== $days && count($days) < 7) {
                $playTime = '(' . $this->formatDays($days) . ') and ' . $playTime;
            }

            return $this->applyScheduleDateRangeBounds($event, $schedule, $playTime);
        }

        $overnight = $schedule->start_time > $schedule->end_time;

        if (!$overnight && 0 === $dayOffset && $startSeconds >= $endSeconds) {
            // Grace consumed the entire short schedule window.
            return 'false';
        }

        $parts = [];

        if ($overnight && 0 === $dayOffset && $startSeconds > $endSeconds) {
            $currentDays = $schedule->days;
            $nextDays = $this->shiftDays($schedule->days, 1);

            $first = $this->formatSecondsCode($startSeconds) . '-23h59m59s';
            $second = '00h00m00s-' . $this->formatSecondsCode($endSeconds);

            if ([] !== $currentDays && count($currentDays) < 7) {
                $first = '(' . $this->formatDays($currentDays) . ') and ' . $first;
                $second = '(' . $this->formatDays($nextDays) . ') and ' . $second;
            }

            $parts = [$first, $second];
        } else {
            $effectiveDays = $this->shiftDays($schedule->days, $dayOffset);
            if ($startSeconds >= $endSeconds) {
                return 'false';
            }

            $single = $this->formatSecondsCode($startSeconds)
                . '-' . $this->formatSecondsCode($endSeconds);
            if ([] !== $effectiveDays && count($effectiveDays) < 7) {
                $single = '(' . $this->formatDays($effectiveDays) . ') and ' . $single;
            }
            $parts = [$single];
        }

        $playTime = count($parts) > 1
            ? '(' . implode(') or (', $parts) . ')'
            : $parts[0];

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
        $method = 'bounded_flexible_' . $key . '_date_range';
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
                // One extra grace minute may cross midnight. Extending the
                // upper bound by exactly the grace amount preserves that final
                // occurrence instead of silently dropping its deadline.
                $rangeEnd = $endDateObj->setTime(23, 59, 59)
                    ->addSeconds(self::FLEXIBLE_GRACE_SECONDS);
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
