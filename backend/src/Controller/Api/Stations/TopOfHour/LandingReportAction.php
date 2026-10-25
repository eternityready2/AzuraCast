<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\TopOfHour;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\AutoDJ\TopOfHour\TopOfHourLandingReport;
use Psr\Http\Message\ResponseInterface;

/**
 * How each hour met the Top-of-Hour ID, from the air history: over the last
 * "days" days, or between "start" and "end" when a report page gives a range.
 */
final class LandingReportAction implements SingleActionInterface
{
    public function __construct(
        private readonly TopOfHourLandingReport $landingReport,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $query = $request->getQueryParams();

        $start = is_string($query['start'] ?? null) ? strtotime($query['start']) : false;
        $end = is_string($query['end'] ?? null) ? strtotime($query['end']) : false;

        if (false !== $start && false !== $end) {
            return $response->withJson($this->landingReport->build($station, $start, $end));
        }

        $days = $query['days'] ?? null;

        return $response->withJson(
            $this->landingReport->buildForDays(
                $station,
                is_numeric($days) ? (int)$days : TopOfHourLandingReport::DEFAULT_DAYS,
            )
        );
    }
}
