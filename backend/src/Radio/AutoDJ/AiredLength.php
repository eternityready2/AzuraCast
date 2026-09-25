<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\AutoCueCache;
use App\Entity\StationMedia;

/**
 * How long a track actually occupies the air, from the AutoCue cue points
 * Liquidsoap plays it with. Measured on station 2 over 82 plays on
 * 2026-09-24: start-to-next-start is cue_out - cue_in to within ~0.1s, while
 * the file length was off by up to 17s (5s on average).
 */
final class AiredLength
{
    /** Median file-length minus aired-length for tracks AutoCue has not analysed yet. */
    private const float UNKNOWN_TRIM_SECONDS = 3.5;

    public function __construct(
        private readonly AutoCueCache $autoCueCache,
    ) {
    }

    /**
     * @return array{length: float, fade_out: float, known: bool}
     */
    public function forMedia(StationMedia $media): array
    {
        $cue = $this->autoCueCache->getForCacheKey($this->autoCueCache->getCacheKey($media));

        if (null !== $cue && isset($cue['cue_out']) && is_numeric($cue['cue_out'])) {
            $cueIn = is_numeric($cue['cue_in'] ?? null) ? (float)$cue['cue_in'] : 0.0;
            $cueOut = (float)$cue['cue_out'];

            if ($cueOut > $cueIn) {
                return [
                    'length' => $cueOut - $cueIn,
                    'fade_out' => is_numeric($cue['fade_out'] ?? null) ? max(0.0, (float)$cue['fade_out']) : 0.0,
                    'known' => true,
                ];
            }
        }

        return [
            'length' => max(0.0, $media->getCalculatedLength() - self::UNKNOWN_TRIM_SECONDS),
            'fade_out' => 0.0,
            'known' => false,
        ];
    }

    public function lengthOf(?StationMedia $media, ?float $fallbackDuration = null): float
    {
        if (null !== $media) {
            return $this->forMedia($media)['length'];
        }

        return max(0.0, (float)($fallbackDuration ?? 0.0) - self::UNKNOWN_TRIM_SECONDS);
    }
}
