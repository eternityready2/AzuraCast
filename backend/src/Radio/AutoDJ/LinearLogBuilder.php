<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Message\AbstractMessage;
use App\Message\BuildLinearLogMessage;
use App\Utilities\Time;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class LinearLogBuilder
{
    use EntityManagerAwareTrait;

    /**
     * The selected horizon is a minimum. Keep one additional program hour so a
     * 24-hour log does not hard-stop at exactly +24:00 and operators can see the
     * handoff into the next hour. This also gives the scheduled 12-hour rebuild a
     * small safety runway if a build is delayed.
     */
    public const int SAFETY_RUNWAY_MINUTES = 60;

    public function __construct(
        private readonly Queue $queue,
        private readonly StationQueueRepository $queueRepo,
        private readonly StationRepository $stationRepo,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogPreviewContext $previewContext,
        private readonly AiDjScheduleRepository $aiDjScheduleRepo,
        private readonly RigidScheduleWindowResolver $rigidScheduleWindowResolver,
    ) {
    }

    public function __invoke(AbstractMessage $message): void
    {
        if (!$message instanceof BuildLinearLogMessage) {
            return;
        }

        $station = $this->stationRepo->findByIdentifier((string)$message->stationId);
        if (!$station instanceof Station || !$station->supportsAutoDjQueue()) {
            return;
        }

        if (!$message->force && !$station->backend_config->linear_log_enabled) {
            $this->snapshotStore->cancelQueued($station);
            return;
        }

        $this->build($station, $message->hours);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Station $station, ?int $hoursOverride = null): array
    {
        $stationId = $station->id;
        $hours = max(1, min(48, $hoursOverride ?? $station->backend_config->linear_log_hours));
        $requestedLookaheadMinutes = $hours * 60;
        $lookaheadMinutes = $requestedLookaheadMinutes + self::SAFETY_RUNWAY_MINUTES;
        $maxTracks = max(1000, $lookaheadMinutes * 2);
        $buildStartedAt = time();
        $projectionStart = Time::nowUtc();
        $projectionStartTs = $projectionStart->getTimestamp();
        $projectionEnd = $projectionStart->modify('+' . $lookaheadMinutes . ' minutes');
        $projectionEndTs = $projectionEnd->getTimestamp();

        // Strict/programme schedules are rendered by a native Liquidsoap source
        // above the ordinary AutoDJ queue. Capture those windows before the
        // isolated queue projection so the report can replace underlay rows with
        // truthful programme blocks instead of pretending General Rotation airs.
        $rigidWindows = $this->rigidScheduleWindowResolver->getWindows(
            $station,
            $projectionStart,
            $projectionEnd,
        );

        $liveQueueIds = [];
        foreach ($this->queueRepo->getUnplayedQueue($station) as $queueRow) {
            $liveQueueIds[$queueRow->id] = true;
        }

        $this->snapshotStore->markBuilding($station, $hours);

        $this->previewContext->begin();

        $connection = $this->em->getConnection();
        $entries = [];
        $gaps = [];
        $coverageEnd = $projectionStartTs;

        try {
            $connection->beginTransaction();

            $gaps = $this->queue->buildQueue(
                $station,
                $lookaheadMinutes,
                $maxTracks,
                true,
            );

            $rows = $this->queueRepo->getUnplayedQueue($station);
            usort(
                $rows,
                static fn(StationQueue $a, StationQueue $b): int =>
                    ($a->timestamp_played?->getTimestamp() ?? 0) <=> ($b->timestamp_played?->getTimestamp() ?? 0)
            );

            $sequence = 0;
            foreach ($rows as $row) {
                $playedAt = $row->timestamp_played?->getTimestamp();
                if (null === $playedAt) {
                    continue;
                }

                if ($playedAt < ($projectionStartTs - 300) || $playedAt > $projectionEndTs) {
                    continue;
                }

                $entry = $this->mapQueueRow(
                    $row,
                    ++$sequence,
                    isset($liveQueueIds[$row->id]),
                );
                $entry = $this->applyRigidWindowsToQueueEntry($entry, $rigidWindows);
                if (null === $entry) {
                    continue;
                }

                $duration = max(1.0, (float)$entry['duration']);
                $coverageEnd = max($coverageEnd, $playedAt + (int)ceil($duration));
                $entries[] = $entry;
            }

            // Preview gaps beneath a native rigid programme are not real on-air
            // gaps. Remove them instead of alarming the operator with impossible
            // holes inside a programme that Liquidsoap is already playing.
            $gaps = array_values(array_filter(
                $gaps,
                fn(array $gap): bool => !$this->rangeOverlapsRigidWindow(
                    (int)$gap['started_at'],
                    (int)$gap['started_at'] + (int)$gap['duration'],
                    $rigidWindows,
                ),
            ));

            foreach ($gaps as $gap) {
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$gap['started_at'] + (int)$gap['duration'],
                );
            }

            foreach ($rigidWindows as $window) {
                $marker = $this->mapRigidScheduleWindow(
                    $window,
                    $projectionStartTs,
                    $projectionEndTs,
                );
                if (null === $marker) {
                    continue;
                }

                $entries[] = $marker;
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$marker['played_at'] + (int)ceil((float)$marker['duration']),
                );
            }

            usort(
                $entries,
                static fn(array $a, array $b): int =>
                    ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)),
            );
        } catch (Throwable $e) {
            $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
            throw $e;
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->previewContext->end();
            $this->em->clear();
        }

        $managedStation = $this->stationRepo->findByIdentifier((string)$stationId);
        if (!$managedStation instanceof Station) {
            $error = 'Station could not be reloaded after Linear Log preview.';
            $this->snapshotStore->markFailed($station, $hours, $error);
            throw new RuntimeException($error);
        }
        $station = $managedStation;

        $aiDjShifts = $this->buildAiDjShifts($station, $projectionStartTs, $projectionEndTs);

        $this->snapshotStore->storeReady(
            $station,
            $hours,
            $buildStartedAt,
            $projectionStartTs,
            $coverageEnd,
            $entries,
            $gaps,
            $aiDjShifts,
        );

        return $this->snapshotStore->get($station);
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}> $windows
     * @return array<string, mixed>|null
     */
    private function applyRigidWindowsToQueueEntry(array $entry, array $windows): ?array
    {
        // TOH IDs sit above rigid schedules and really can air inside the block.
        if ((bool)$entry['top_of_hour_legal_id']) {
            return $entry;
        }

        $start = (int)($entry['played_at'] ?? 0);
        $end = $start + (int)ceil((float)$entry['duration']);

        foreach ($windows as $window) {
            $windowStart = $window['start']->getTimestamp();
            $windowEnd = $window['end']->getTimestamp();

            if ($start >= $windowStart && $start < $windowEnd) {
                return null;
            }

            // A normal AutoDJ item may begin before an exact programme boundary.
            // It really airs only until the rigid takeover, so clip its projected
            // duration instead of showing it continuing underneath the programme.
            if ($start < $windowStart && $end > $windowStart) {
                $entry['duration'] = max(1.0, (float)($windowStart - $start));
                return $entry;
            }
        }

        return $entry;
    }

    /**
     * @param array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable} $window
     * @return array<string, mixed>|null
     */
    private function mapRigidScheduleWindow(array $window, int $projectionStartTs, int $projectionEndTs): ?array
    {
        $start = max($projectionStartTs, $window['start']->getTimestamp());
        $end = min($projectionEndTs, $window['end']->getTimestamp());
        if ($end <= $start) {
            return null;
        }

        $playlist = $window['playlist'];
        $schedule = $window['schedule'];
        $scheduleId = isset($schedule->id) ? $schedule->id : spl_object_id($schedule);

        return [
            'id' => 'rigid-schedule-' . $scheduleId . '-' . $start,
            'queue_id' => 0,
            'song_id' => '',
            'played_at' => $start,
            'cued_at' => $start,
            'duration' => (float)($end - $start),
            'title' => $playlist->name,
            'artist' => null,
            'album' => null,
            'text' => 'Strict scheduled programme',
            'playlist' => $playlist->name,
            'playlist_id' => isset($playlist->id) ? $playlist->id : null,
            'playlist_chain' => null,
            'clock_wheel' => null,
            'clock_wheel_id' => null,
            'media_type' => 'programme',
            'source_type' => 'scheduled_programme',
            'is_request' => false,
            'is_live_queue' => false,
            'sent_to_autodj' => false,
            'top_of_hour_legal_id' => false,
            'autodj_custom_uri' => null,
            'clock_wheel_schedule_mode' => null,
            'clock_wheel_enforce_cap' => false,
            'clock_wheel_stretch_ratio' => null,
            'clock_wheel_legal_id_substitute' => false,
            'hour_boundary_enforce_cap' => false,
            'hour_boundary_max_play_seconds' => null,
            'top_of_hour_pre_id_fade' => false,
        ];
    }

    /**
     * @param list<array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}> $windows
     */
    private function rangeOverlapsRigidWindow(int $start, int $end, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($start < $window['end']->getTimestamp() && $window['start']->getTimestamp() < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * AI DJ speech is deliberately not generated by the preview. These rows only
     * describe scheduled work shifts so the log still shows who is expected to be
     * on-air while all speech timing and content remain live.
     *
     * @return list<array<string, mixed>>
     */
    private function buildAiDjShifts(Station $station, int $startTs, int $endTs): array
    {
        $timezone = $station->getTimezoneObject();
        $firstDay = (new DateTimeImmutable('@' . $startTs))
            ->setTimezone($timezone)
            ->setTime(0, 0)
            ->modify('-1 day');
        $lastDay = (new DateTimeImmutable('@' . $endTs))
            ->setTimezone($timezone)
            ->setTime(0, 0);

        $shifts = [];
        foreach ($this->aiDjScheduleRepo->findByStation($station->id) as $schedule) {
            $dj = $schedule->getAiDj();
            if (!$schedule->isEnabled() || !$dj->isEnabled()) {
                continue;
            }

            for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
                if (!in_array((int)$day->format('N'), $schedule->getLoopDays(), true)) {
                    continue;
                }

                $startParts = array_map('intval', explode(':', $schedule->getStartTime()->format('H:i:s')));
                $endParts = array_map('intval', explode(':', $schedule->getEndTime()->format('H:i:s')));

                $shiftStart = $day->setTime($startParts[0], $startParts[1], $startParts[2]);
                $shiftEnd = $day->setTime($endParts[0], $endParts[1], $endParts[2]);
                if ($shiftEnd <= $shiftStart) {
                    $shiftEnd = $shiftEnd->modify('+1 day');
                }

                $shiftStartTs = $shiftStart->getTimestamp();
                $shiftEndTs = $shiftEnd->getTimestamp();
                if ($shiftEndTs <= $startTs || $shiftStartTs >= $endTs) {
                    continue;
                }

                $shifts[] = [
                    'schedule_id' => $schedule->getId(),
                    'schedule_name' => $schedule->getName(),
                    'dj_id' => $dj->getId(),
                    'dj_name' => $dj->getName(),
                    'starts_at' => $shiftStartTs,
                    'ends_at' => $shiftEndTs,
                ];
            }
        }

        usort($shifts, static fn(array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $shifts;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapQueueRow(StationQueue $row, int $sequence, bool $isLiveQueue): array
    {
        $mediaType = match (true) {
            $row->top_of_hour_legal_id => 'id',
            null !== $row->autodj_custom_uri => 'stream',
            null !== $row->media => $row->media->type,
            default => 'music',
        };

        $sourceType = match (true) {
            null !== $row->request => 'request',
            null !== $row->clock_wheel => 'clock_wheel',
            null !== $row->autodj_custom_uri => 'stream',
            null !== $row->playlist => 'playlist',
            default => 'autodj',
        };

        return [
            'id' => 'projection-' . $sequence,
            'queue_id' => $row->id,
            'song_id' => $row->song_id,
            'played_at' => $row->timestamp_played?->getTimestamp(),
            'cued_at' => $row->timestamp_cued->getTimestamp(),
            'duration' => max(5.0, $row->duration ?? 0.0),
            'title' => $row->title,
            'artist' => $row->artist,
            'album' => $row->album,
            'text' => $row->text,
            'playlist' => $row->playlist?->name,
            'playlist_id' => $row->playlist?->id,
            'playlist_chain' => $row->playlist_chain,
            'clock_wheel' => $row->clock_wheel?->name,
            'clock_wheel_id' => $row->clock_wheel?->id,
            'media_type' => $mediaType,
            'source_type' => $sourceType,
            'is_request' => null !== $row->request,
            'is_live_queue' => $isLiveQueue,
            'sent_to_autodj' => $row->sent_to_autodj,
            'top_of_hour_legal_id' => $row->top_of_hour_legal_id,
            'autodj_custom_uri' => $row->autodj_custom_uri,
            'clock_wheel_schedule_mode' => $row->clock_wheel_schedule_mode,
            'clock_wheel_enforce_cap' => $row->clock_wheel_enforce_cap,
            'clock_wheel_stretch_ratio' => $row->clock_wheel_stretch_ratio,
            'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
            'hour_boundary_enforce_cap' => $row->hour_boundary_enforce_cap,
            'hour_boundary_max_play_seconds' => $row->hour_boundary_max_play_seconds,
            'top_of_hour_pre_id_fade' => $row->top_of_hour_pre_id_fade,
        ];
    }
}
