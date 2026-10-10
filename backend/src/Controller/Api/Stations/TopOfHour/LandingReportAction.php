<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\TopOfHour;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\AutoDJ\TopOfHour\TopOfHourLandingReport;
use Psr\Http\Message\ResponseInterface;

/**
 * How the last song or spot of each hour met the Top-of-Hour ID, over the last
 * "days" days of air history.
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
        $days = $request->getQueryParams()['days'] ?? null;

        return $response->withJson(
            $this->landingReport->build(
                $request->getStation(),
                is_numeric($days) ? (int)$days : TopOfHourLandingReport::DEFAULT_DAYS,
            )
        );
    }
}
