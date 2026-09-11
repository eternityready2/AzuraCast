<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistRemoteTypes;
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
 * Gives rigid scheduled playlists real wall-clock authority over the final
 * station source graph.
 *
 * The plugin writes this wrapper immediately below the TOH ID wrapper. The
 * resulting authority order is:
 *
 *     Top-of-Hour ID -> rigid scheduled programme -> live/AutoDJ
 *
 * A rigid takeover uses the same one-shot clean AutoDJ cut as TOH: the real
 * request.dynamic leaf is skipped once and the shared cross operator discards
 * its buffered old tail at that forced boundary. There is no secondary gate or
 * backend quarantine state machine.
 *
 * Rigid branches intentionally use dedicated native source instances. A source
 * already written by ConfigWriter may live below stretch()/cross() in the normal
 * radio graph; reusing that same source again above those operators creates a
 * nested clock graph that Liquidsoap rejects with Error 11. Keeping the rigid
 * source independent preserves the wall-clock takeover while leaving Stretch /
 * Squeeze and crossfade enabled on the ordinary radio path.
 */
final class RigidScheduleRuntimeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 16],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $rigidBranches = [];

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            // These sources are resolved through the PHP AutoDJ queue and do not
            // have a static media file/URL that a native wall-clock source can read.
            if (in_array($playlist->source, [PlaylistSources::Playlists, PlaylistSources::Requests], true)) {
                continue;
            }

            // Members of a Playlist Group are owned by the group runtime, not by
            // a standalone Liquidsoap source.
            if (count($playlist->group_memberships) > 0) {
                continue;
            }

            $rigidSchedules = [];
            foreach ($playlist->schedule_items as $scheduleItem) {
                if ($this->isRigidSchedule($playlist, $scheduleItem)) {
                    $rigidSchedules[] = $scheduleItem;
                }
            }

            if ([] === $rigidSchedules) {
                continue;
            }

            // Never reuse ConfigWriter's source in the outer rigid switch. The
            // configured source can already exist below stretch()/cross(); sharing
            // it across both sides of that clock boundary creates the nested-clock
            // startup failure. A single dedicated source per rigid playlist keeps
            // its own sequential/shuffle state across all rigid schedule windows.
            $playlistId = isset($playlist->id) ? $playlist->id : spl_object_id($playlist);
            $playlistVarName = 'rigid_' . ConfigWriter::getPlaylistVariableName($playlist) . '_' . $playlistId;

            if (!$this->writeDedicatedSource($event, $playlist, $playlistVarName)) {
                continue;
            }

            foreach ($rigidSchedules as $scheduleItem) {
                $playTime = $this->getScheduledPlaylistPlayTime($event, $scheduleItem);
                $rigidBranches[] = $playlist->backendPlaySingleTrack()
                    ? '(predicate.at_most(1, {' . $playTime . '}), ' . $playlistVarName . ')'
                    : '({ ' . $playTime . ' }, ' . $playlistVarName . ')';
            }
        }

        // This state is only emitted as a helper definition. With no rigid
        // branches below, this subscriber does not wrap or replace `radio`.
        $event->appendBlock(
            <<<'LIQ'
            # Rigid schedule state (Top-of-Hour plugin).
            rigid_schedule_active = ref(false)
            LIQ
        );

        if ([] === $rigidBranches) {
            return;
        }

        $branches = implode(",\n                    ", $rigidBranches);
        $transitions = implode(
            ', ',
            [...array_fill(0, count($rigidBranches), 'rigid_schedule_enter'), 'rigid_schedule_exit'],
        );

        $event->appendBlock(
            <<<LIQ
            # Rigid scheduled-programme wall-clock lane (Top-of-Hour plugin).
            radio_before_rigid_schedule = radio

            def rigid_schedule_enter(_, new) =
                rigid_schedule_active := true

                if not azuracast.live_enabled() then
                    # Arm a one-shot destructive cross boundary and skip exactly
                    # one real AutoDJ request. If a HARD TOH immediately preceded
                    # this rigid item, the pending guard makes this a no-op so the
                    # already-fresh successor cannot be skipped a second time.
                    azuracast.discard_autodj_current_cleanly()
                    log("Rigid Schedule: armed clean cross boundary for interrupted AutoDJ request.")
                end

                # The PHP strict-start path may have staged a duplicate copy in
                # the interrupting queue. This native rigid lane is authoritative.
                interrupting_queue.skip()
                interrupting_queue.set_queue([])

                log("Rigid Schedule: scheduled programme took wall-clock authority.")
                new
            end

            def rigid_schedule_exit(_, new) =
                # Anything staged into the legacy interrupting lane while the
                # rigid source was on air is stale and must not follow it.
                interrupting_queue.skip()
                interrupting_queue.set_queue([])
                rigid_schedule_active := false

                log("Rigid Schedule: scheduled programme released wall-clock authority.")
                new
            end

            radio = switch(
                id="rigid_schedule_runtime",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                transitions=[{$transitions}],
                [
                    {$branches},
                    ({true}, radio_before_rigid_schedule)
                ]
            )
            LIQ
        );
    }

    /**
     * Write an isolated native source that mirrors ConfigWriter's source setup
     * closely enough for the rigid lane to preserve playlist behavior without
     * sharing the same Liquidsoap source object across clock domains.
     */
    private function writeDedicatedSource(
        WriteLiquidsoapConfiguration $event,
        StationPlaylist $playlist,
        string $playlistVarName,
    ): bool {
        if (PlaylistSources::Songs === $playlist->source) {
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
                '# Dedicated native source for a rigid scheduled programme.',
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

            return true;
        }

        if (PlaylistSources::RemoteUrl !== $playlist->source) {
            return false;
        }

        $remoteUrl = $playlist->remote_url;
        if (null === $remoteUrl) {
            return false;
        }

        if (PlaylistRemoteTypes::Playlist === $playlist->remote_type) {
            $event->appendLines([
                '# Dedicated native source for a rigid scheduled programme.',
                $playlistVarName . ' = playlist(' . ConfigWriter::toRawString($remoteUrl) . ')',
            ]);

            if ($playlist->is_jingle) {
                $event->appendLines([
                    $playlistVarName . ' = azuracast.utilities.drop_metadata(' . $playlistVarName . ')',
                ]);
            }

            return true;
        }

        $buffer = $playlist->remote_buffer;
        $buffer = ($buffer < 1) ? StationPlaylist::DEFAULT_REMOTE_BUFFER : $buffer;

        $inputFunc = match ($playlist->remote_type) {
            PlaylistRemoteTypes::Stream => 'input.http',
            default => 'input.ffmpeg',
        };

        $remoteUrlFunc = 'buffer(buffer=' . $buffer . '., '
            . $inputFunc . '(' . ConfigWriter::toRawString($remoteUrl) . '))';

        $event->appendLines([
            '# Dedicated native source for a rigid scheduled programme.',
            $playlistVarName . ' = mksafe(' . $remoteUrlFunc . ')',
        ]);

        return true;
    }

    private function isRigidSchedule(
        StationPlaylist $playlist,
        StationSchedule $schedule,
    ): bool {
        return $schedule->strict_start
            || $schedule->is_emergency
            || $playlist->backendInterruptOtherSongs();
    }

    /**
     * Mirrors ConfigWriter's schedule predicate generation so the outer rigid
     * runtime uses the exact same station-local schedule windows.
     */
    private function getScheduledPlaylistPlayTime(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $playlistSchedule,
    ): string {
        $tzObject = $event->getStation()->getTimezoneObject();

        if (ScheduleRecurrence::hasRecurrence($playlistSchedule)) {
            $now = CarbonImmutable::now($tzObject);
            $rangeEnd = $now->addDays(400);
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $playlistSchedule,
                $tzObject,
                $now->subDay(),
                $rangeEnd,
                500,
            );
            if ([] === $occurrences) {
                return 'false';
            }

            $scheduleMethod = 'rigid_schedule_' . $playlistSchedule->id . '_recurrence';
            $parts = [];
            foreach ($occurrences as $dateRange) {
                $startTs = $dateRange->start->getTimestamp();
                $endTs = $dateRange->end->getTimestamp();
                $parts[] = "(time() >= $startTs. and time() <= $endTs.)";
            }

            $event->appendLines([
                'def ' . $scheduleMethod . '() =',
                '  (' . implode(' or ', $parts) . ')',
                'end',
            ]);

            return $scheduleMethod . '()';
        }

        $startTime = $playlistSchedule->start_time;
        $endTime = $playlistSchedule->end_time;

        if ($startTime > $endTime) {
            $playTimes = [
                ConfigWriter::formatTimeCode($startTime) . '-23h59m59s',
                '00h00m-' . ConfigWriter::formatTimeCode($endTime),
            ];

            $playlistScheduleDays = $playlistSchedule->days;
            if ([] !== $playlistScheduleDays && count($playlistScheduleDays) < 7) {
                $currentPlayDays = [];
                $nextPlayDays = [];

                foreach ($playlistScheduleDays as $day) {
                    $currentPlayDays[] = (($day === 7) ? '0' : $day) . 'w';

                    $day++;
                    if ($day > 7) {
                        $day = 1;
                    }
                    $nextPlayDays[] = (($day === 7) ? '0' : $day) . 'w';
                }

                $playTimes[0] = '(' . implode(' or ', $currentPlayDays) . ') and ' . $playTimes[0];
                $playTimes[1] = '(' . implode(' or ', $nextPlayDays) . ') and ' . $playTimes[1];
            }

            $playTime = '(' . implode(') or (', $playTimes) . ')';
            return $this->applyScheduleDateRangeBounds($event, $playlistSchedule, $playTime);
        }

        $playTime = ($startTime === $endTime)
            ? ConfigWriter::formatTimeCode($startTime)
            : ConfigWriter::formatTimeCode($startTime) . '-' . ConfigWriter::formatTimeCode($endTime);

        $playlistScheduleDays = $playlistSchedule->days;
        if ([] !== $playlistScheduleDays && count($playlistScheduleDays) < 7) {
            $playDays = [];
            foreach ($playlistScheduleDays as $day) {
                $playDays[] = (($day === 7) ? '0' : $day) . 'w';
            }
            $playTime = '(' . implode(' or ', $playDays) . ') and ' . $playTime;
        }

        return $this->applyScheduleDateRangeBounds($event, $playlistSchedule, $playTime);
    }

    private function applyScheduleDateRangeBounds(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $playlistSchedule,
        string $playTime,
    ): string {
        $startDate = $playlistSchedule->start_date;
        $endDate = $playlistSchedule->end_date;

        if (empty($startDate) && empty($endDate)) {
            return $playTime;
        }

        $tzObject = $event->getStation()->getTimezoneObject();
        $scheduleMethod = 'rigid_schedule_' . $playlistSchedule->id . '_date_range';
        $body = ['def ' . $scheduleMethod . '() ='];
        $conditions = [];

        if (!empty($startDate)) {
            $startDateObj = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tzObject);
            if (null !== $startDateObj) {
                $startDateObj = $startDateObj->setTime(0, 0);
                $body[] = '    range_start = ' . $startDateObj->getTimestamp() . '.';
                $conditions[] = 'range_start <= current_time';
            }
        }

        if (!empty($endDate)) {
            $endDateObj = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $tzObject);
            if (null !== $endDateObj) {
                $endDateObj = $endDateObj->setTime(23, 59, 59);
                $body[] = '    range_end = ' . $endDateObj->getTimestamp() . '.';
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

        return $scheduleMethod . '() and ' . $playTime;
    }
}
