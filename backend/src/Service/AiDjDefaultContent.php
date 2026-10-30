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
 *
 * Stations set up before the library shipped carry the King James Bible that
 * older versions loaded. While that set is exactly as it was loaded (the same
 * verses, every one enabled and global), it is replaced by the library's
 * World English Bible; a station that has changed any verse keeps its own.
 */
final class AiDjDefaultContent
{
    use EntityManagerAwareTrait;

    public const string LIBRARY_FILE = __DIR__ . '/../../resources/ai_dj/default_content.json.gz';

    private const int INSERT_BATCH = 500;

    /** The King James Bible older versions loaded: 31,086 verses, as loaded. */
    private const int LEGACY_KJV_VERSES = 31086;
    private const string LEGACY_KJV_FINGERPRINT = 'fa7d1189622154c963499067927bd3bd';

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

            $conn->transactional(function () use ($station, $type, $rows): void {
                $this->insertRows($station, $type, $rows);
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
     * Replaces the King James Bible older versions loaded with the library's
     * World English Bible, while the station still has that set exactly as it
     * was loaded.
     *
     * @return int verses replaced; 0 when the station's Bible is not that set
     */
    public function replaceLegacyBible(Station $station): int
    {
        $conn = $this->em->getConnection();

        // Cheap check first; this runs for every station on every sync. WEB
        // reads "only born Son" here.
        $isKjv = (bool)$conn->fetchOne(
            'SELECT COUNT(*) FROM ai_dj_content WHERE station_id = ? AND type = ? '
            . 'AND reference = ? AND content LIKE ?',
            [$station->id, AiDjContent::TYPE_BIBLE_VERSE, 'John 3:16', '%only begotten Son%']
        );
        if (!$isKjv) {
            return 0;
        }

        $rows = $conn->fetchAllAssociative(
            'SELECT reference, content, is_enabled, is_global FROM ai_dj_content WHERE station_id = ? AND type = ?',
            [$station->id, AiDjContent::TYPE_BIBLE_VERSE]
        );
        if (self::LEGACY_KJV_VERSES !== count($rows)) {
            return 0;
        }

        $hashes = [];
        foreach ($rows as $row) {
            if (1 !== (int)$row['is_enabled'] || 1 !== (int)$row['is_global']) {
                return 0;
            }
            $hashes[] = [(string)$row['reference'], (string)$row['content']];
        }
        usort(
            $hashes,
            static fn(array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1])
        );
        $fingerprint = md5(implode("\n", array_map(
            static fn(array $row): string => md5($row[0] . "\0" . $row[1]),
            $hashes
        )));
        if (self::LEGACY_KJV_FINGERPRINT !== $fingerprint) {
            return 0;
        }

        $web = $this->library()[AiDjContent::TYPE_BIBLE_VERSE] ?? [];
        if ([] === $web) {
            return 0;
        }

        $conn->transactional(function () use ($conn, $station, $web): void {
            $conn->executeStatement(
                'DELETE FROM ai_dj_content WHERE station_id = ? AND type = ?',
                [$station->id, AiDjContent::TYPE_BIBLE_VERSE]
            );
            $this->insertRows($station, AiDjContent::TYPE_BIBLE_VERSE, $web);
        });

        return count($web);
    }

    /**
     * @param list<array{content: string, reference?: string, is_global?: bool}> $rows
     */
    private function insertRows(Station $station, string $type, array $rows): void
    {
        $conn = $this->em->getConnection();

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
