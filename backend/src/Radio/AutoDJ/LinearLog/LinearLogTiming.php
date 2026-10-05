<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;

/**
 * Hard and soft timing on the saved log, as on FM automation.
 *
 * A hard line must start at a fixed clock time: the first line of a scheduled
 * block (a show or a playlist window), a stream programme, the Top-of-Hour ID,
 * or a line an operator locked. Everything else is soft and simply follows the
 * line before it. The hard starts are already enforced on air -- the ID holds
 * the hour, playout keeps a later hour's lines in their hour and drops what an
 * hour that ran long left over -- so this only names them, for the page and for
 * the check that proves they aired on time.
 */
final class LinearLogTiming
{
    /**
     * How long after its block opens a block's first line may start and still
     * be on time: the Top-of-Hour ID airs first (about 37s), plus a margin.
     */
    public const int HARD_START_TOLERANCE_SECONDS = 75;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    /**
     * Start times of every scheduled library block between two timestamps.
     *
     * @return list<array{playlist_id: int, playlist: string, starts_at: int}>
     */
    public function blockStarts(Station $station, int $from, int $to): array
    {
        $tz = $station->getTimezoneObject();
        $day = CarbonImmutable::createFromTimestamp($from, $tz)->startOfDay()->subDay();
        $lastDay = CarbonImmutable::createFromTimestamp($to, $tz)->startOfDay();

        $starts = [];
        for (; $day->lte($lastDay); $day = $day->addDay()) {
            foreach ($station->playlists as $playlist) {
                // Stream programmes are switched in by Liquidsoap's clock and
                // carry their own programme line.
                if (!$playlist->is_enabled || PlaylistSources::Songs !== $playlist->source) {
                    continue;
                }

                foreach ($playlist->schedule_items as $schedule) {
                    if (!$this->playsOn($schedule, $day)) {
                        continue;
                    }

                    $startsAt = StationSchedule::getDateTime($schedule->start_time, $tz, $day)->getTimestamp();
                    if ($startsAt >= $from && $startsAt < $to) {
                        $starts[] = [
                            'playlist_id' => $playlist->id,
                            'playlist' => $playlist->name,
                            'starts_at' => $startsAt,
                        ];
                    }
                }
            }
        }

        usort($starts, static fn(array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $starts;
    }

    /**
     * Tag each report entry 'hard' or 'soft', with the reason a line is hard.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function tagEntries(Station $station, array $entries): array
    {
        if ([] === $entries) {
            return $entries;
        }

        $times = array_map(static fn(array $e): int => (int)($e['played_at'] ?? 0), $entries);
        $blocks = $this->blockStarts($station, min($times) - 3600, max($times) + 3600);

        // The first line of each block's playlist at or after its start.
        $firstOfBlock = [];
        foreach ($blocks as $block) {
            $best = null;
            foreach ($entries as $i => $entry) {
                if ((int)($entry['playlist_id'] ?? 0) !== $block['playlist_id']) {
                    continue;
                }
                $at = (int)($entry['played_at'] ?? 0);
                if ($at < $block['starts_at'] || $at > $block['starts_at'] + self::HARD_START_TOLERANCE_SECONDS) {
                    continue;
                }
                if (null === $best || $at < (int)$entries[$best]['played_at']) {
                    $best = $i;
                }
            }
            if (null !== $best) {
                $firstOfBlock[$best] = $block['playlist'];
            }
        }

        foreach ($entries as $i => $entry) {
            $reason = match (true) {
                (bool)($entry['top_of_hour_legal_id'] ?? false) => 'Top-of-Hour ID',
                'scheduled_programme' === ($entry['source_type'] ?? null) => 'Scheduled programme',
                isset($firstOfBlock[$i]) => 'Start of ' . trim((string)$firstOfBlock[$i]),
                (bool)($entry['is_locked'] ?? false) => 'Locked by hand',
                default => null,
            };
            $entries[$i]['timing'] = null === $reason ? 'soft' : 'hard';
            $entries[$i]['timing_reason'] = $reason;
        }

        return $entries;
    }

    private function playsOn(StationSchedule $schedule, CarbonImmutable $day): bool
    {
        if (!$this->scheduler->isScheduleScheduledToPlayToday($schedule, $day->dayOfWeekIso)) {
            return false;
        }

        $tz = $day->getTimezone();
        $startsAt = StationSchedule::getDateTime($schedule->start_time, $tz, $day);

        return $this->scheduler->shouldSchedulePlayOnCurrentDate($schedule, $tz, $startsAt);
    }
}
