<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Radio\AutoDJ\AiNewsScheduleForecastService;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLog\LinearLogTiming;
use App\Radio\AutoDJ\RigidScheduleWindowResolver;
use App\Radio\AutoDJ\StrictProgrammeClock;
use App\Utilities\Types;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only checks of the saved linear log, to run before each deploy.
 *
 * Reads the log lines and the station's schedule and reports anything an FM
 * automation log must never contain: holes, lines on top of each other, the
 * same line twice, music planned inside a programme's window, a programme
 * window with no line (or more than one), or an aired programme logged at a
 * time other than its scheduled start. Changes nothing. Exits non-zero when a
 * check fails.
 */
#[AsCommand(
    name: 'azuracast:radio:check-linear-log',
    description: 'Check the saved linear log for holes, overlaps, duplicates and schedule mismatches (read-only).',
)]
final class CheckLinearLogCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    private const int DEFAULT_HOURS = 24;

    private const int DEFAULT_MAX_GAP = 30;

    private const int TOP_OF_HOUR_ID_SECONDS = 40;

    /** Crossfades and write rounding. */
    private const int MAX_OVERLAP = 10;

    /** Margin after the estimated end of the ID and news, which varies a little. */
    private const int OPENS_AT_MARGIN_SECONDS = 38;

    public function __construct(
        private readonly StationRepository $stationRepo,
        private readonly RigidScheduleWindowResolver $windowResolver,
        private readonly LinearLogTiming $timing,
        private readonly AiNewsScheduleForecastService $newsForecast,
        private readonly StrictProgrammeClock $strictProgrammeClock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station-name', InputArgument::OPTIONAL)
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Hours ahead to check.', (string)self::DEFAULT_HOURS)
            ->addOption(
                'max-gap',
                null,
                InputOption::VALUE_REQUIRED,
                'Longest allowed silence between two lines, in seconds.',
                (string)self::DEFAULT_MAX_GAP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $hours = max(1, min(48, Types::int($input->getOption('hours'), self::DEFAULT_HOURS)));
        $maxGap = max(0, Types::int($input->getOption('max-gap'), self::DEFAULT_MAX_GAP));

        $stationName = Types::stringOrNull($input->getArgument('station-name'));
        if (!empty($stationName)) {
            $station = $this->stationRepo->findByIdentifier($stationName);
            if (!$station instanceof Station) {
                $io->error('Station not found.');
                return 1;
            }
            $stations = [$station];
        } else {
            $stations = $this->stationRepo->fetchAll();
        }

        $failed = false;
        foreach ($stations as $station) {
            if (!LinearLogPlayout::isPlayoutEnabled($station)) {
                continue;
            }

            $io->section(sprintf('%s: next %d hour(s)', $station->name, $hours));

            $rows = [];
            foreach ($this->check($station, $hours, $maxGap) as [$name, $problems]) {
                $rows[] = [$name, [] === $problems ? 'pass' : 'FAIL', count($problems)];
                foreach (array_slice($problems, 0, 10) as $problem) {
                    $io->writeln(sprintf('  [%s] %s', $name, $problem));
                }
                if (count($problems) > 10) {
                    $io->writeln(sprintf('  [%s] ... and %d more', $name, count($problems) - 10));
                }
                $failed = $failed || [] !== $problems;
            }

            $io->table(['Check', 'Result', 'Problems'], $rows);
        }

        if ($failed) {
            $io->error('Linear log checks failed.');
            return 1;
        }

        $io->success('Linear log checks passed.');
        return 0;
    }

    /**
     * @return list<array{string, list<string>}>
     */
    private function check(Station $station, int $hours, int $maxGap): array
    {
        $tz = $station->getTimezoneObject();
        $now = time();
        $until = $now + $hours * 3600;
        $at = static fn(int $ts): string => CarbonImmutable::createFromTimestamp($ts, $tz)->format('D H:i:s');

        $conn = $this->em->getConnection();

        // A line in the live queue starts when the queue has it and runs as
        // long as its queue row, not as the plan had it: the air runs ahead of
        // the plan as an hour goes on, and measured from the plan, the 36s it
        // was ahead at 21:44 read as a hole before the next song. The row is
        // also what airs when the Top-of-Hour swap changed the song (Wed
        // 2026-10-07).
        /** @var list<array<string, mixed>> $open */
        $open = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT e.id, e.status, e.planned_at, e.duration, e.title, e.media_id, e.playlist_id, e.payload,
                    q.queued_at,
                    COALESCE(q.queued_at, e.planned_at) AS starts_at,
                    COALESCE(q.queued_duration, e.duration) AS runs_for
                FROM station_log_entries e
                LEFT JOIN (
                    SELECT sq.log_entry_id, UNIX_TIMESTAMP(sq.timestamp_played) AS queued_at,
                        sq.duration AS queued_duration
                    FROM station_queue sq
                    WHERE sq.station_id = ? AND sq.is_played = 0 AND sq.autodj_custom_uri IS NULL
                    AND sq.log_entry_id IS NOT NULL
                ) q ON q.log_entry_id = e.id AND e.status = 'queued'
                WHERE e.station_id = ? AND e.status IN ('planned', 'queued')
                AND e.planned_at + e.duration > ? AND e.planned_at < ?
                ORDER BY e.planned_at ASC, e.id ASC
            SQL,
            [$station->id, $station->id, $now, $until]
        );

        $isProgramme = static fn(array $row): bool => str_contains((string)($row['payload'] ?? ''), 'scheduled_programme');

        // Depth, holes and overlaps.
        $depth = [];
        $holes = [];
        $overlaps = [];
        $duplicates = [];
        $seen = [];
        // What is on air now is already as-run; the open lines follow it.
        $onAirEnd = (int)$conn->fetchOne(
            <<<'SQL'
                SELECT MAX(COALESCE(e.aired_at, e.planned_at) + e.duration)
                FROM station_log_entries e
                WHERE e.station_id = ? AND e.status IN ('aired', 'swapped', 'replaced')
                AND COALESCE(e.aired_at, e.planned_at) BETWEEN ? AND ?
            SQL,
            [$station->id, $now - 7200, $now]
        );
        $cursor = max($now, $onAirEnd);

        // Hour boundaries with an AI News bulletin after the ID, and how long
        // recent bulletins ran (the same estimate the Top-of-Hour swap uses).
        $newsAt = [];
        foreach (
            $this->newsForecast->getAiringTimes(
                $station,
                CarbonImmutable::createFromTimestamp($now)->toDateTimeImmutable(),
                CarbonImmutable::createFromTimestamp($until)->toDateTimeImmutable(),
            ) as $airsAt
        ) {
            $newsAt[(int)(round($airsAt->getTimestamp() / 3600) * 3600)] = true;
        }
        $newsSeconds = (int)ceil((float)($conn->fetchOne(
            'SELECT AVG(d) FROM (
                SELECT duration AS d FROM song_history
                WHERE station_id = ? AND text = ? AND duration > 30
                ORDER BY id DESC LIMIT 5
            ) recent',
            [$station->id, 'Eternity Ready - News Hour']
        ) ?: 150.0));

        $laneCovered = static function (int $from, int $to) use ($newsAt, $newsSeconds): int {
            $covered = 0;
            for ($hour = (int)(floor($from / 3600) * 3600); $hour <= $to + 3600; $hour += 3600) {
                $laneStart = $hour - 1;
                $laneEnd = $hour + self::TOP_OF_HOUR_ID_SECONDS + (isset($newsAt[$hour]) ? $newsSeconds : 0);
                $covered += max(0, min($to, $laneEnd) - max($from, $laneStart));
            }
            return $covered;
        };
        $previous = null;
        // In the order the lines air: a song the AutoDJ added sits at the end
        // of the queue, wherever the time it was picked for falls in the plan.
        $airOrder = $open;
        usort(
            $airOrder,
            static fn(array $a, array $b): int => [(int)$a['starts_at'], (int)$a['id']]
                <=> [(int)$b['starts_at'], (int)$b['id']]
        );

        // The rest of the hour the live queue is in follows the queue: playout
        // takes those lines one after another from where the queue ends, as the
        // page draws them, whatever times the plan gave them.
        $queueEnd = 0;
        foreach ($open as $row) {
            if (null !== $row['queued_at']) {
                $queueEnd = max($queueEnd, (int)$row['queued_at'] + (int)ceil((float)$row['runs_for']));
            }
        }
        $queueHourEnd = $queueEnd > 0
            ? CarbonImmutable::createFromTimestamp($queueEnd, $tz)->startOfHour()->addHour()->getTimestamp()
            : 0;

        foreach ($airOrder as $row) {
            $start = (int)$row['starts_at'];
            if (
                'planned' === $row['status']
                && !$isProgramme($row)
                && $start < $queueHourEnd
                && $cursor < $queueHourEnd
            ) {
                $start = $cursor;
            }
            $end = $start + (int)ceil((float)$row['runs_for']);

            $key = (int)$row['planned_at'] . '|' . $row['title'];
            if (isset($seen[$key])) {
                $duplicates[] = sprintf('%s "%s" (lines %d and %d)', $at($start), $row['title'], $seen[$key], $row['id']);
            }
            $seen[$key] = (int)$row['id'];

            // The Top-of-Hour ID, and the AI News after it, air from their own
            // lane, so they are not log lines. Only air outside that lane can be
            // a hole: counting it as one flagged every news hour and every hour
            // whose last song ran past :00.
            if ($start - $cursor - $laneCovered($cursor, $start) > $maxGap) {
                $holes[] = sprintf(
                    '%s to %s: %ds with no line (before "%s")',
                    $at($cursor),
                    $at($start),
                    $start - $cursor,
                    $row['title']
                );
            }
            if (null !== $previous && $cursor - $start > self::MAX_OVERLAP) {
                $overlaps[] = sprintf(
                    '%s "%s" starts %ds before "%s" ends',
                    $at($start),
                    $row['title'],
                    $cursor - $start,
                    $previous['title']
                );
            }

            if ($end > $cursor) {
                $cursor = $end;
                $previous = $row;
            }
        }
        if ($until - $cursor > $maxGap) {
            $depth[] = sprintf('the log ends at %s, %d min short of %d hours', $at($cursor), intdiv($until - $cursor, 60), $hours);
        }

        // Programme windows: stream programmes own their window on the log.
        $windowProblems = [];
        $leaks = [];
        $windows = $this->windowResolver->getWindows(
            $station,
            new DateTimeImmutable('@' . $now),
            new DateTimeImmutable('@' . $until),
        );
        foreach ($windows as $window) {
            $playlist = $window['playlist'];
            if (PlaylistSources::RemoteUrl !== $playlist->source) {
                continue;
            }

            $windowStart = $window['start']->getTimestamp();
            $windowEnd = $window['end']->getTimestamp();

            if ($windowStart > $now) {
                $lines = array_filter(
                    $open,
                    static fn(array $row): bool => $isProgramme($row)
                        && (int)$row['playlist_id'] === $playlist->id
                        && (int)$row['planned_at'] === $windowStart
                );
                if (1 !== count($lines)) {
                    $windowProblems[] = sprintf(
                        '%s "%s": %d programme line(s) at its start, expected 1',
                        $at($windowStart),
                        $playlist->name,
                        count($lines)
                    );
                }
            }

            foreach ($open as $row) {
                $start = (int)$row['planned_at'];
                if (!$isProgramme($row) && $start >= max($windowStart, $now) && $start < $windowEnd) {
                    $leaks[] = sprintf('%s "%s" is planned inside "%s"', $at($start), $row['title'], $playlist->name);
                }
            }
        }

        // As-run, last 24 hours: a programme aired at its scheduled start, once.
        $asRun = [];
        foreach (
            $conn->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.id, e.planned_at, e.aired_at, e.status, e.title, e.payload
                    FROM station_log_entries e
                    JOIN station_playlists p ON p.id = e.playlist_id
                    WHERE e.station_id = ? AND e.media_id IS NULL AND p.source = 'remote_url'
                    AND e.planned_at BETWEEN ? AND ?
                    ORDER BY e.planned_at ASC
                SQL,
                [$station->id, $now - 86400, $now]
            ) as $row
        ) {
            $start = (int)$row['planned_at'];
        if (!$isProgramme($row)) {
            if ('aired' === $row['status']) {
                $asRun[] = sprintf('%s "%s": logged as a live item, not on its programme line (line %d)', $at($start), $row['title'], $row['id']);
            }
            continue;
        }
        if ('aired' !== $row['status'] || (int)$row['aired_at'] !== $start) {
            $asRun[] = sprintf('%s "%s": programme line is "%s", not aired at its start (line %d)', $at($start), $row['title'], $row['status'], $row['id']);
        }
        }

        // Hard starts: a scheduled block's first line opens the block on time,
        // in the plan ahead and on the air behind (song history is what played).
        $hardStarts = [];
        $tolerance = LinearLogTiming::HARD_START_TOLERANCE_SECONDS;
        foreach ($this->timing->blockStarts($station, $now - 86400, $until) as $block) {
            $blockStart = $block['starts_at'];
            $name = trim($block['playlist']);

            if ($blockStart > $now) {
                $first = null;
                foreach ($open as $row) {
                    if ((int)$row['playlist_id'] === $block['playlist_id'] && (int)$row['planned_at'] >= $blockStart - 5) {
                        $first = $row;
                        break;
                    }
                }
                if (null === $first) {
                    $hardStarts[] = sprintf('%s "%s": no line planned for its start', $at($blockStart), $name);
                } elseif ((int)$first['planned_at'] > $blockStart + $tolerance) {
                    $hardStarts[] = sprintf(
                        '%s "%s": first line planned %ds after its start',
                        $at($blockStart),
                        $name,
                        (int)$first['planned_at'] - $blockStart
                    );
                }
                continue;
            }

            // Only judge a block that opened at least a few minutes ago.
            if ($blockStart > $now - 300) {
                continue;
            }
            $firstAired = $conn->fetchOne(
                <<<'SQL'
                    SELECT UNIX_TIMESTAMP(MIN(h.timestamp_start)) FROM song_history h
                    WHERE h.station_id = ? AND h.playlist_id = ?
                    AND h.timestamp_start >= FROM_UNIXTIME(?) AND h.timestamp_start < FROM_UNIXTIME(?)
                SQL,
                [$station->id, $block['playlist_id'], $blockStart - 5, $blockStart + 3600]
            );
            // A block opening on the hour starts once the Top-of-Hour ID, and
            // in a News hour the bulletin, release the air: Faith Horizons
            // (17:00, News hour) aired at 17:03:25 and was reported late.
            $opensAt = $this->strictProgrammeClock
                ->airFreeFrom($station, CarbonImmutable::createFromTimestamp($blockStart, 'UTC'))
                ->getTimestamp();
            $onTimeBy = max($blockStart + $tolerance, $opensAt + self::OPENS_AT_MARGIN_SECONDS);

            if (null === $firstAired || false === $firstAired) {
                $hardStarts[] = sprintf('%s "%s": did not air in its first hour', $at($blockStart), $name);
            } elseif ((int)$firstAired > $onTimeBy) {
                $hardStarts[] = sprintf(
                    '%s "%s": started %ds late',
                    $at($blockStart),
                    $name,
                    (int)$firstAired - $blockStart
                );
            }
        }

        return [
            ['24h depth', $depth],
            ['No holes', $holes],
            ['No overlaps', $overlaps],
            ['No duplicate lines', $duplicates],
            ['Programme windows have one line', $windowProblems],
            ['No music inside a programme window', $leaks],
            ['Programmes as-run at their start', $asRun],
            ['Hard starts on time', $hardStarts],
        ];
    }
}
