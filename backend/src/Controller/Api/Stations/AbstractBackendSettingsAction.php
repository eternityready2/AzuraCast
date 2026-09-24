<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * GET/PUT a fixed subset of the station backend configuration, so a feature's
 * own page (DMCA, Playout Log, AI DJ) can load and save just its settings.
 */
abstract class AbstractBackendSettingsAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    /**
     * @return array<string, array{type: 'bool'|'int', min?: int, max?: int, default: bool|int}>
     */
    abstract protected function fields(): array;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());

        if ('GET' === $request->getMethod()) {
            $raw = $station->backend_config->toArray(true) ?? [];

            $values = [];
            foreach ($this->fields() as $key => $field) {
                $value = $raw[$key] ?? $field['default'];
                $values[$key] = 'bool' === $field['type']
                    ? filter_var($value, FILTER_VALIDATE_BOOL)
                    : (int)$value;
            }

            return $response->withJson($values);
        }

        $body = (array)$request->getParsedBody();
        $updates = [];
        foreach ($this->fields() as $key => $field) {
            if (!array_key_exists($key, $body)) {
                continue;
            }

            if ('bool' === $field['type']) {
                $updates[$key] = filter_var($body[$key], FILTER_VALIDATE_BOOL);
                continue;
            }

            $value = filter_var($body[$key], FILTER_VALIDATE_INT);
            if (false === $value || $value < $field['min'] || $value > $field['max']) {
                return $response->withStatus(400)->withJson(
                    new Error(400, sprintf('%s must be between %d and %d.', $key, $field['min'], $field['max']))
                );
            }
            $updates[$key] = $value;
        }

        if ([] !== $updates) {
            // These settings are read live by PHP; none needs a broadcast restart.
            $originalNeedsRestart = $station->needs_restart;

            $config = $station->backend_config;
            $config->fromArray($updates);
            $station->backend_config = $config;
            $station->needs_restart = $originalNeedsRestart;

            $this->em->persist($station);
            $this->em->flush();
        }

        return $response->withJson(Status::updated());
    }
}
