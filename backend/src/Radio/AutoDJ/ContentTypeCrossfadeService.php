<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use App\Entity\StationBackendConfiguration;
use App\Entity\StationPlaylist;

/**
 * Resolves fade_in/fade_out for content-type transitions (Master Plan §7).
 */
final class ContentTypeCrossfadeService
{
    /** @var array<string, array{fade_in: float, fade_out: float}> */
    public const array DEFAULT_MATRIX = [
        'music:legal_id' => ['fade_in' => 0.0, 'fade_out' => 0.0],
        'music:id' => ['fade_in' => 0.5, 'fade_out' => 0.5],
        'legal_id:music' => ['fade_in' => 1.0, 'fade_out' => 0.0],
        'id:music' => ['fade_in' => 1.5, 'fade_out' => 1.0],
        'music:promo' => ['fade_in' => 1.0, 'fade_out' => 1.0],
        'promo:music' => ['fade_in' => 1.5, 'fade_out' => 1.0],
        'music:ad' => ['fade_in' => 0.5, 'fade_out' => 0.5],
        'ad:music' => ['fade_in' => 1.5, 'fade_out' => 1.0],
        'talk:music' => ['fade_in' => 2.0, 'fade_out' => 1.5],
        'music:talk' => ['fade_in' => 1.5, 'fade_out' => 1.5],
    ];

    /** @var string[] */
    public const array CONTENT_TYPES = [
        'music',
        'talk',
        'legal_id',
        'id',
        'promo',
        'ad',
    ];

    /**
     * @return array{
     *     enabled: bool,
     *     matrix: array<string, array{fade_in: float, fade_out: float}|null>,
     *     profiles: array<string, array<string, array{fade_in: float, fade_out: float}|null>>,
     *     content_types: array<int, string>,
     *     defaults: array<string, array{fade_in: float, fade_out: float}>
     * }
     */
    public function getSettingsForStation(Station $station): array
    {
        $config = $station->backend_config;

        return [
            'enabled' => $config->content_type_crossfade_enabled,
            'matrix' => $config->content_type_crossfade_matrix,
            'profiles' => $config->crossfade_profiles,
            'content_types' => self::CONTENT_TYPES,
            'defaults' => self::DEFAULT_MATRIX,
        ];
    }

    /**
     * @return array{fade_in: float, fade_out: float}|null
     */
    public function resolveTransitionFades(
        Station $station,
        string $fromType,
        string $toType,
        ?StationPlaylist $playlist = null,
    ): ?array {
        if (!$station->backend_config->content_type_crossfade_enabled) {
            return null;
        }

        $fromType = $this->normalizeType($fromType);
        $toType = $this->normalizeType($toType);
        $key = $fromType . ':' . $toType;

        $profileName = $playlist?->crossfade_profile;
        $matrix = $this->buildEffectiveMatrix($station->backend_config, $profileName);

        if (!array_key_exists($key, $matrix)) {
            return null;
        }

        $entry = $matrix[$key];
        if (null === $entry) {
            return null;
        }

        return [
            'fade_in' => round((float)$entry['fade_in'], 1),
            'fade_out' => round((float)$entry['fade_out'], 1),
        ];
    }

    /**
     * @return array<string, array{fade_in: float, fade_out: float}|null>
     */
    private function buildEffectiveMatrix(
        StationBackendConfiguration $config,
        ?string $profileName,
    ): array {
        $matrix = self::DEFAULT_MATRIX;

        foreach ($config->content_type_crossfade_matrix as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $matrix[$key] = $this->normalizeEntry($value);
        }

        if (null !== $profileName && $profileName !== '') {
            $profile = $config->crossfade_profiles[$profileName] ?? null;
            if (is_array($profile)) {
                foreach ($profile as $key => $value) {
                    if (!is_string($key)) {
                        continue;
                    }

                    $matrix[$key] = $this->normalizeEntry($value);
                }
            }
        }

        return $matrix;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, self::CONTENT_TYPES, true) ? $type : 'music';
    }

    /**
     * @return array{fade_in: float, fade_out: float}|null
     */
    private function normalizeEntry(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        return [
            'fade_in' => round((float)($value['fade_in'] ?? 0), 1),
            'fade_out' => round((float)($value['fade_out'] ?? 0), 1),
        ];
    }
}
