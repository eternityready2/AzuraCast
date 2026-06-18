<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Controller\SingleActionInterface;
use App\Controller\Api\Traits\AcceptsDateRange;
use App\Entity\SongHistory;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\Types;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

#[OA\Get(
    path: '/station/{station_id}/reports/programme-log',
    operationId: 'getStationProgrammeLog',
    summary: 'Broadcast programme log (as-played) with playlist and clock wheel context.',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(name: 'start', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'end', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100)),
        new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
        new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv'])),
    ],
    responses: [
        new OA\Response\Success(),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class ProgrammeLogAction implements SingleActionInterface
{
    use AcceptsDateRange;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $tz = $station->getTimezoneObject();
        $dateRange = $this->getDateRange($request, $tz);

        $limit = max(1, min(500, Types::intOrNull($request->getQueryParam('limit')) ?? 100));
        $offset = max(0, Types::intOrNull($request->getQueryParam('offset')) ?? 0);
        $format = (string)($request->getQueryParam('format') ?? 'json');

        $qb = $this->em->createQueryBuilder()
            ->select('sh', 'p', 'cw', 'm')
            ->from(SongHistory::class, 'sh')
            ->leftJoin('sh.playlist', 'p')
            ->leftJoin('sh.clock_wheel', 'cw')
            ->leftJoin('sh.media', 'm')
            ->where('sh.station = :station')
            ->andWhere('sh.is_visible = 1')
            ->andWhere('sh.timestamp_start >= :start')
            ->andWhere('sh.timestamp_start <= :end')
            ->setParameter('station', $station)
            ->setParameter('start', $dateRange->start->toDateTimeImmutable())
            ->setParameter('end', $dateRange->end->toDateTimeImmutable())
            ->orderBy('sh.timestamp_start', 'DESC');

        $countQb = clone $qb;
        $total = (int)$countQb->select('COUNT(sh.id)')->getQuery()->getSingleScalarResult();

        /** @var SongHistory[] $rows */
        $rows = $qb->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->execute();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row->id,
                'played_at' => CarbonImmutable::instance($row->timestamp_start)
                    ->setTimezone($tz)
                    ->format(DATE_ATOM),
                'title' => $row->title,
                'artist' => $row->artist,
                'album' => $row->album,
                'duration' => $row->duration,
                'listeners_start' => $row->listeners_start,
                'playlist' => $row->playlist?->name,
                'clock_wheel' => $row->clock_wheel?->name,
                'media_id' => $row->media_id,
            ];
        }

        if ($format === 'csv') {
            return $this->csvResponse($response, $items);
        }

        return $response->withJson([
            'rows' => $items,
            'total' => $total,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function csvResponse(Response $response, array $items): ResponseInterface
    {
        $writer = Writer::createFromString('');
        $writer->insertOne([
            'played_at',
            'title',
            'artist',
            'album',
            'duration',
            'listeners_start',
            'playlist',
            'clock_wheel',
            'media_id',
        ]);

        foreach ($items as $item) {
            $writer->insertOne([
                $item['played_at'],
                $item['title'],
                $item['artist'],
                $item['album'],
                $item['duration'],
                $item['listeners_start'],
                $item['playlist'],
                $item['clock_wheel'],
                $item['media_id'],
            ]);
        }

        $csv = $writer->toString();
        if ($csv === '') {
            throw new RuntimeException('Could not generate CSV.');
        }

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="programme-log.csv"')
            ->write($csv);
    }
}
