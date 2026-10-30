<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Service\AiDjDefaultContent;
use Throwable;

/**
 * Gives every station, new or upgraded, the AI DJ content library that ships
 * with the fork (AiDjDefaultContent).
 */
final class AiDjDefaultContentTask extends AbstractTask
{
    public function __construct(
        private readonly AiDjDefaultContent $defaultContent,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '*/5 * * * *';
    }

    public function run(bool $force = false): void
    {
        // Not iterateStations(): its transaction would hold the station row
        // for the whole library insert.
        /** @var Station[] $stations */
        $stations = $this->em->createQuery(
            <<<'DQL'
                SELECT s FROM App\Entity\Station s
            DQL
        )->getResult();

        foreach ($stations as $station) {
            try {
                $replaced = $this->defaultContent->replaceLegacyBible($station);
                if (0 !== $replaced) {
                    $this->logger->notice('AI DJ: the old King James Bible was replaced by the World English Bible.', [
                        'station_id' => $station->id,
                        'verses' => $replaced,
                    ]);
                }

                $added = $this->defaultContent->seed($station);
                if ([] !== $added) {
                    $this->logger->notice('AI DJ: default content library added.', [
                        'station_id' => $station->id,
                        'added' => $added,
                    ]);
                }
            } catch (Throwable $e) {
                $this->logger->error('AI DJ: default content library failed.', [
                    'station_id' => $station->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
