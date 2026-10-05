<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\StationClockWheel;
use App\Entity\StationSchedule;
use App\Exception\NotFoundException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\Scheduler;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeZone;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * What the system really chose for a wheel, read from the saved Linear Log (not
 * a simulation): the lines planned for its next airing, and the as-run lines of
 * its recent airings. Each airing window lists every line in it, with the ones
 * the wheel itself picked marked. Read-only.
 */
#[OA\Get(
    path: '/station/{station_id}/clock-wheel/{id}/log',
    operationId: 'getClockWheelLog',
    summary: 'Linear Log lines for a clock wheel\'s next airing and its recent airings.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    responses: [
        new OA\Response\Success(),
        new OA\Response\AccessDenied(),
        new OA\Response\NotFound(),
        new OA\Response\GenericError(),
    ]
)]
final class WheelLogAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    /** How far back and ahead airings are looked up. */
    private const int LOOK_DAYS = 14;

    /** Recent airings returned. */
    private const int MAX_PAST_AIRINGS = 5;

    private const array AIRED_STATUSES = ['aired', 'swapped', 'replaced'];

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $wheel = $this->em->find(StationClockWheel::class, (int)$params['id']);
        if (!$wheel instanceof StationClockWheel || $wheel->station_id !== $station->id) {
            throw NotFoundException::generic();
        }

        $tz = $station->getTimezoneObject();
        $now = CarbonImmutable::now($tz);
        $conn = $this->em->getConnection();

        $windows = $this->airingWindows($wheel, $tz, $now);

        $logUntil = (int)$conn->fetchOne(
            'SELECT MAX(planned_at + duration) FROM station_log_entries
            WHERE station_id = ? AND status IN (?, ?)',
            [$station->id, 'planned', 'queued']
        );

        $next = null;
        foreach ($windows as [$start, $end]) {
            if ($end > $now->getTimestamp()) {
                $next = [$start, $end];
                break;
            }
        }

        $upcoming = null;
        if (null !== $next) {
            $upcoming = [
                'start' => $next[0],
                'end' => $next[1],
                'in_log' => $logUntil >= $next[0],
                'lines' => $this->linesIn($station->id, $wheel->id, $next[0], $next[1]),
            ];
        }

        $past = array_reverse(array_values(array_filter(
            $windows,
            static fn(array $w): bool => $w[1] <= $now->getTimestamp()
        )));
        $aired = [];
        foreach (array_slice($past, 0, self::MAX_PAST_AIRINGS) as [$start, $end]) {
            $aired[] = [
                'start' => $start,
                'end' => $end,
                'lines' => $this->linesIn($station->id, $wheel->id, $start, $end, true),
            ];
        }

        return $response->withJson([
            'wheel_id' => $wheel->id,
            'log_until' => $logUntil > 0 ? $logUntil : null,
            'upcoming' => $upcoming,
            'aired' => $aired,
        ]);
    }

    /**
     * Airing windows of the wheel within LOOK_DAYS either side of now, as
     * [start, end] epochs in time order.
     *
     * @return list<array{int, int}>
     */
    private function airingWindows(StationClockWheel $wheel, DateTimeZone $tz, CarbonImmutable $now): array
    {
        $from = $now->subDays(self::LOOK_DAYS)->startOfDay();
        $to = $now->addDays(self::LOOK_DAYS)->endOfDay();

        /** @var StationSchedule[] $items */
        $items = $this->em->getRepository(StationSchedule::class)->findBy(['clock_wheel' => $wheel]);

        $windows = [];
        foreach ($items as $item) {
            if (ScheduleRecurrence::hasRecurrence($item)) {
                foreach (ScheduleRecurrence::getOccurrencesInRange($item, $tz, $from, $to, 200) as $range) {
                    $windows[] = [$range->start->getTimestamp(), $range->end->getTimestamp()];
                }
                continue;
            }

            for ($day = $from; $day <= $to; $day = $day->addDay()) {
                if (
                    !$this->scheduler->shouldSchedulePlayOnCurrentDate($item, $tz, $day)
                    || !$this->scheduler->isScheduleScheduledToPlayToday($item, $day->dayOfWeekIso)
                ) {
                    continue;
                }

                $start = StationSchedule::getDateTime($item->start_time, $tz, $day);
                $end = StationSchedule::getDateTime($item->end_time, $tz, $day);
                if ($end <= $start) {
                    $end = $end->addDay();
                }
                $windows[] = [$start->getTimestamp(), $end->getTimestamp()];
            }
        }

        usort($windows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $windows;
    }

    /**
     * Every log line in a window, marking the ones this wheel picked.
     *
     * @return list<array<string, mixed>>
     */
    private function linesIn(int $stationId, int $wheelId, int $start, int $end, bool $airedOnly = false): array
    {
        $sql = 'SELECT e.id, e.planned_at, e.aired_at, e.duration, e.status, e.title, e.artist, e.text,
                e.note, e.payload, m.type AS media_type, p.name AS playlist
            FROM station_log_entries e
            LEFT JOIN station_media m ON m.id = e.media_id
            LEFT JOIN station_playlists p ON p.id = e.playlist_id
            WHERE e.station_id = ?
            AND COALESCE(e.aired_at, e.planned_at) >= ? AND COALESCE(e.aired_at, e.planned_at) < ?';
        $args = [$stationId, $start, $end];

        if ($airedOnly) {
            $sql .= ' AND e.status IN (?, ?, ?)';
            array_push($args, ...self::AIRED_STATUSES);
        } else {
            $sql .= ' AND e.status <> ?';
            $args[] = 'dropped';
        }
        $sql .= ' ORDER BY COALESCE(e.aired_at, e.planned_at), e.sequence';

        $lines = [];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql, $args) as $row) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];

            $lines[] = [
                'id' => (int)$row['id'],
                'at' => (int)($row['aired_at'] ?? $row['planned_at']),
                'duration' => (float)$row['duration'],
                'status' => (string)$row['status'],
                'title' => $row['title'] ?? $row['text'],
                'artist' => $row['artist'],
                'type' => $row['media_type'] ?? ($payload['media_type'] ?? null),
                'playlist' => $row['playlist'],
                'note' => $row['note'],
                'from_wheel' => (int)($payload['clock_wheel_id'] ?? 0) === $wheelId,
            ];
        }

        return $lines;
    }
}
