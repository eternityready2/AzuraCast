<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared hour-boundary math for clock wheels and station-wide top-of-hour protection.
 */
final class HourBoundaryPlanner
{
    public const int HOUR_SECONDS = 3600;

    public const int DEFAULT_LOOKAHEAD_MINUTES = 10;

    public const int DEFAULT_FINISH_BUFFER_SECONDS = 15;

    public const int DEFAULT_COMPLIANCE_TOLERANCE_SECONDS = 10;

    public const int DEFAULT_ID_MAX_SECONDS = 60;

    public const int MIN_LOOKAHEAD_MINUTES = 1;

    public const int MAX_LOOKAHEAD_MINUTES = 30;

    public const int MIN_FINISH_BUFFER_SECONDS = 0;

    public const int MAX_FINISH_BUFFER_SECONDS = 30;

    public const int MIN_COMPLIANCE_TOLERANCE_SECONDS = 1;

    public const int MAX_COMPLIANCE_TOLERANCE_SECONDS = 60;

    public const int MIN_ID_MAX_SECONDS = 15;

    public const int MAX_ID_MAX_SECONDS = 120;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public function isTopOfHourProtectionEnabled(Station $station): bool
    {
        return $station->backend_config->top_of_hour_id_enabled;
    }

    public function getComplianceToleranceSeconds(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_compliance_tolerance_seconds,
            self::MIN_COMPLIANCE_TOLERANCE_SECONDS,
            self::MAX_COMPLIANCE_TOLERANCE_SECONDS,
            self::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
        );
    }

    public function getLookaheadMinutes(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_lookahead_minutes,
            self::MIN_LOOKAHEAD_MINUTES,
            self::MAX_LOOKAHEAD_MINUTES,
            self::DEFAULT_LOOKAHEAD_MINUTES,
        );
    }

    public function getFinishBufferSeconds(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_finish_buffer_seconds,
            self::MIN_FINISH_BUFFER_SECONDS,
            self::MAX_FINISH_BUFFER_SECONDS,
            self::DEFAULT_FINISH_BUFFER_SECONDS,
        );
    }

    public function getIdMaxSeconds(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_id_max_seconds,
            self::MIN_ID_MAX_SECONDS,
            self::MAX_ID_MAX_SECONDS,
            self::DEFAULT_ID_MAX_SECONDS,
        );
    }

    /**
     * Planned position within the broadcast hour (0–3599), using expected play time
     * and already-queued items in the same hour.
     */
    public function getPlannedSecondsIntoHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        ?DateTimeZone $tz = null,
    ): int {
        $tz ??= $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $seconds = $local->getTimestamp() - $hourStart->getTimestamp();

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $playedAt = $row->timestamp_played;
            if ($playedAt === null) {
                continue;
            }

            $queuedLocal = CarbonImmutable::instance($playedAt)->setTimezone($tz);
            if ($queuedLocal->format('Y-m-d H') !== $local->format('Y-m-d H')) {
                continue;
            }

            if ($queuedLocal->greaterThanOrEqualTo($local)) {
                continue;
            }

            $queuedHourStart = $queuedLocal->startOf('hour');
            $queuedStartOffset = $queuedLocal->getTimestamp() - $queuedHourStart->getTimestamp();
            $queuedEndOffset = $queuedStartOffset + (int)ceil((float)($row->duration ?? 0));

            $seconds = max($seconds, min($queuedEndOffset, self::HOUR_SECONDS - 1));
        }

        return min(max(0, $seconds), self::HOUR_SECONDS - 1);
    }

    /**
     * Expected wall-clock time for the next mandatory top-of-hour legal ID.
     */
    public function resolveTopOfHourExpectedPlayAt(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): DateTimeImmutable {
        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $secondsIntoHour = $local->getTimestamp() - $hourStart->getTimestamp();

        if ($secondsIntoHour > 30) {
            return $hourStart->addHour()->toDateTimeImmutable();
        }

        return $hourStart->toDateTimeImmutable();
    }

    public function getNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');

        if ($local->greaterThan($hourStart)) {
            return $hourStart->addHour()->toDateTimeImmutable();
        }

        return $hourStart->toDateTimeImmutable();
    }

    public function secondsUntilNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): int {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $nextTop = CarbonImmutable::instance($this->getNextTopOfHour($expectedPlayTime, $tz));

        return max(0, $nextTop->getTimestamp() - $local->getTimestamp());
    }

    public function isInLookaheadZone(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $lookaheadSeconds = $this->getLookaheadMinutes($station) * 60;

        return $secondsUntil > 0 && $secondsUntil <= $lookaheadSeconds;
    }

    /**
     * Max music duration (seconds) so playback finishes before `:00` with finish buffer + ID headroom.
     * Returns null when protection is off or outside the lookahead window.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isInLookaheadZone($station, $expectedPlayTime)) {
            return null;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $buffer = $this->getFinishBufferSeconds($station) + $this->getIdMaxSeconds($station);

        return max(1.0, (float)($secondsUntil - $buffer));
    }

    /**
     * True when AutoDJ should queue the mandatory legal ID for this build tick.
     */
    public function isTopOfHourIdDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $targetHourStart = $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $targetLocal = CarbonImmutable::instance($targetHourStart)->setTimezone($tz);

        if ($local->format('Y-m-d H:i:s') !== $targetLocal->format('Y-m-d H:i:s')) {
            return false;
        }

        return !$this->hasTopOfHourIdQueued($station, $targetLocal, $tz);
    }

    /**
     * When station-wide top-of-hour protection is on, legacy once-per-hour playlists
     * pinned to minute :00 are suppressed — {@see TopOfHourIdScheduler} queues legal_id instead.
     */
    public function shouldSuppressOncePerHourPlaylist(StationPlaylist $playlist): bool
    {
        if (!$this->isTopOfHourProtectionEnabled($playlist->station)) {
            return false;
        }

        return $playlist->type === PlaylistTypes::OncePerHour
            && $playlist->play_per_hour_minute === 0;
    }

    public function hasTopOfHourIdQueued(
        Station $station,
        CarbonImmutable $hourStart,
        ?DateTimeZone $tz = null,
    ): bool {
        $tz ??= $station->getTimezoneObject();
        $hourEnd = $hourStart->addHour();

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $playedAt = $row->timestamp_played;
            if ($playedAt === null) {
                continue;
            }

            $queuedLocal = CarbonImmutable::instance($playedAt)->setTimezone($tz);
            if ($queuedLocal->lessThan($hourStart) || $queuedLocal->greaterThanOrEqualTo($hourEnd)) {
                continue;
            }

            if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
                return true;
            }

            $media = $row->media;
            if ($media !== null && $media->type === 'legal_id') {
                return true;
            }
        }

        return false;
    }

    private function clampInt(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
