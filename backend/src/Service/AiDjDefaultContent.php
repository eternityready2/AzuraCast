<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\AiDjContent;
use App\Entity\Station;
use RuntimeException;

/**
 * The AI DJ content library that ships with the fork (resources/ai_dj): song
 * intro and post-song templates, the whole Bible (World English Bible), jokes,
 * encouragement, inspiration, testimonies and stories.
 *
 * A station gets a category's library once, and only while it has none of
 * its own in that category. A category the station has removed is left alone
 * until it is restored; one emptied by hand is not refilled.
 */
final class AiDjDefaultContent
{
    use EntityManagerAwareTrait;

    public const string LIBRARY_FILE = __DIR__ . '/../../resources/ai_dj/default_content.json.gz';

    private const int INSERT_BATCH = 500;

    /**
     * @return array<string, int> rows added, by category
     */
    public function seed(Station $station): array
    {
        $config = $station->backend_config;
        $pending = array_values(array_diff(
            AiDjContent::TYPES,
            $config->ai_dj_default_content_types,
            $config->ai_dj_removed_content_types
        ));
        if ([] === $pending) {
            return [];
        }

        $library = $this->library();
        $conn = $this->em->getConnection();
        $added = [];

        foreach ($pending as $type) {
            $own = (int)$conn->fetchOne(
                'SELECT COUNT(*) FROM ai_dj_content WHERE station_id = ? AND type = ?',
                [$station->id, $type]
            );
            $rows = $library[$type] ?? [];
            if (0 !== $own || [] === $rows) {
                continue;
            }

            $conn->transactional(function () use ($conn, $station, $type, $rows): void {
                foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
                    $values = [];
                    $params = [];
                    foreach ($chunk as $row) {
                        $values[] = '(?, ?, ?, ?, 1, ?)';
                        array_push(
                            $params,
                            $station->id,
                            $type,
                            $row['content'],
                            $row['reference'] ?? null,
                            ($row['is_global'] ?? true) ? 1 : 0
                        );
                    }
                    $conn->executeStatement(
                        'INSERT INTO ai_dj_content (station_id, type, content, reference, is_enabled, is_global) VALUES '
                        . implode(', ', $values),
                        $params
                    );
                }
            });
            $added[$type] = count($rows);
        }

        // Saved onto the station row as it is now; the backend_config setter
        // marks a restart, which a content library does not need.
        $this->em->refresh($station);
        $needsRestartBefore = $station->needs_restart;
        $config = $station->backend_config;
        $config->ai_dj_default_content_types = [...$config->ai_dj_default_content_types, ...$pending];
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $added;
    }

    /**
     * @return array<string, list<array{content: string, reference?: string, is_global?: bool}>>
     */
    private function library(): array
    {
        $raw = @file_get_contents(self::LIBRARY_FILE);
        $json = false !== $raw ? @gzdecode($raw) : false;
        if (false === $json) {
            throw new RuntimeException('AI DJ content library is missing or unreadable: ' . self::LIBRARY_FILE);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return (array)($data['types'] ?? []);
    }
}
