<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDj;
use DateTimeImmutable;
use Throwable;

/**
 * A DJ's own recorded breaks: now and then one airs as an extra break, beside
 * the AI breaks and never in place of one.
 *
 * The files sit on the uploads volume, one folder per DJ, already levelled to
 * the AI voice's loudness, beside an index of their lengths and a note of what
 * has aired:
 *
 *     ai_dj/recordings/dj<id>/*.mp3, index.json, aired.json
 *
 * The path has to contain "/ai_dj/". The stuck-speech check finds a queued DJ
 * clip's row by that, and clears from the air queue any clip it finds no row for.
 */
final class AiDjRecordings
{
    public const string DIRECTORY = '/var/azuracast/storage/uploads/ai_dj/recordings';

    /** Longer than this costs the hour more than one song: never picked. */
    private const float MAX_SECONDS = 210.0;

    /** The hour's recordings share the minutes between these two evenly. */
    private const int FIRST_MINUTE = 4;
    private const int LAST_MINUTE = 40;

    /** How far into its share of the hour a recording's turn may open. */
    private const float TURN_OPENS_WITHIN = 0.4;

    private const int MIN_SECONDS_APART = 600;

    private const int HOURS_REMEMBERED = 48;

    public function available(AiDj $dj): int
    {
        return count($this->recordings($dj));
    }

    /**
     * True when a recording may air at $airsAt (station time):
     * the DJ has them switched on, this clock hour has not had its share, the
     * last one was not just now, and the hour's next turn has opened.
     */
    public function isDue(AiDj $dj, DateTimeImmutable $airsAt): bool
    {
        $perHour = $dj->getRecordingsPerHour();
        if ($perHour <= 0 || [] === $this->recordings($dj)) {
            return false;
        }

        $state = $this->state($dj);
        $airedThisHour = (int)($state['hours'][$airsAt->format('YmdH')] ?? 0);
        if ($airedThisHour >= $perHour) {
            return false;
        }

        if ($airsAt->getTimestamp() - (int)($state['last_at'] ?? 0) < self::MIN_SECONDS_APART) {
            return false;
        }

        $minute = (int)$airsAt->format('i') + (int)$airsAt->format('s') / 60;

        return $minute >= $this->turnOpensAt($dj, $airsAt, $airedThisHour, $perHour);
    }

    /**
     * A recording no longer than $maxSeconds, picked at random from the ones
     * that have not aired yet. Once every one has had its turn the round starts
     * again.
     *
     * @return array{path: string, name: string, seconds: float}|null
     */
    public function pick(AiDj $dj, float $maxSeconds): ?array
    {
        $state = $this->state($dj);

        $fits = array_filter(
            $this->recordings($dj),
            static fn(float $seconds): bool => $seconds <= $maxSeconds
        );

        $fresh = array_diff_key($fits, array_fill_keys((array)($state['played'] ?? []), true));
        if ([] === $fresh) {
            // All that fit have aired this round: any of them again, bar the last one played.
            $fresh = $fits;
            if (count($fresh) > 1) {
                unset($fresh[(string)($state['last'] ?? '')]);
            }
        }
        if ([] === $fresh) {
            return null;
        }

        $name = (string)array_rand($fresh);

        return ['path' => $this->directory($dj) . '/' . $name, 'name' => $name, 'seconds' => $fresh[$name]];
    }

    /** Call once the recording has been queued to air at $airsAt (station time). */
    public function markQueued(AiDj $dj, string $name, DateTimeImmutable $airsAt): void
    {
        $state = $this->state($dj);

        $played = array_values(array_unique([...(array)($state['played'] ?? []), $name]));
        if ([] === array_diff(array_keys($this->recordings($dj)), $played)) {
            // Every recording has had its turn: the next round starts.
            $played = [];
        }

        $hours = (array)($state['hours'] ?? []);
        $hour = $airsAt->format('YmdH');
        $hours[$hour] = (int)($hours[$hour] ?? 0) + 1;

        try {
            file_put_contents(
                $this->directory($dj) . '/aired.json',
                json_encode(
                    [
                        'played' => $played,
                        'last' => $name,
                        'last_at' => $airsAt->getTimestamp(),
                        'hours' => array_slice($hours, -self::HOURS_REMEMBERED, null, true),
                    ],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                ),
                LOCK_EX
            );
        } catch (Throwable) {
            // The recording still airs; at worst one repeats sooner than it should.
        }
    }

    /**
     * The minute of the hour from which the hour's next recording may air. Each
     * of the hour's recordings has an even share of the minutes between
     * FIRST_MINUTE and LAST_MINUTE, and its turn opens somewhere early in that
     * share: a different point from hour to hour, the same all through one
     * hour. Without it the recording would always be the hour's first break.
     */
    private function turnOpensAt(AiDj $dj, DateTimeImmutable $airsAt, int $airedThisHour, int $perHour): float
    {
        $share = (self::LAST_MINUTE - self::FIRST_MINUTE) / $perHour;
        $point = (crc32($dj->getId() . '|' . $airsAt->format('YmdH') . '|' . $airedThisHour) % 1000) / 1000;

        return self::FIRST_MINUTE + $airedThisHour * $share + $point * $share * self::TURN_OPENS_WITHIN;
    }

    /** @return array<string, float> file name => seconds, for the files that are there and short enough */
    private function recordings(AiDj $dj): array
    {
        $dir = $this->directory($dj);

        $recordings = [];
        foreach ($this->readJson($dir . '/index.json') as $name => $seconds) {
            if (
                is_string($name)
                && is_numeric($seconds)
                && (float)$seconds > 0.0
                && (float)$seconds <= self::MAX_SECONDS
                && basename($name) === $name
                && is_file($dir . '/' . $name)
            ) {
                $recordings[$name] = (float)$seconds;
            }
        }

        return $recordings;
    }

    /** @return array<mixed> */
    private function state(AiDj $dj): array
    {
        return $this->readJson($this->directory($dj) . '/aired.json');
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function directory(AiDj $dj): string
    {
        return self::DIRECTORY . '/dj' . $dj->getId();
    }
}
