<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads Piper voice configs (the .onnx.json next to each model).
 *
 * A "mood" voice is a multi-speaker model whose speaker_id_map names delivery
 * styles (e.g. Kim Rasmussen: warm, calm, upbeat, amused). Each style can carry
 * its own pace/expression in a "mood_inference" block.
 */
final class PiperVoices
{
    /** Voices added on this server (e.g. AI-cloned). Lives on a volume so it survives image updates. */
    public const string CUSTOM_VOICES_DIR = '/var/azuracast/storage/uploads/piper-voices';

    /** The fork's own voices shipped in the image (Kim Rasmussen), see util/docker/web/setup/piper.sh. */
    public const string BUNDLED_VOICES_DIR = '/usr/local/share/piper-station-voices';

    /** @var array<string, array<string, mixed>> */
    private static array $configs = [];

    /**
     * @return array<string, int> mood => speaker id; empty unless the model has 2+ named moods
     */
    public static function moods(string $modelPath): array
    {
        $map = self::config($modelPath)['speaker_id_map'] ?? null;
        if (!is_array($map) || count($map) < 2) {
            return [];
        }

        return array_map('intval', $map);
    }

    /**
     * @return array{length_scale?: float, noise_scale?: float, noise_w?: float}
     */
    public static function inference(string $modelPath, ?string $mood = null): array
    {
        $config = self::config($modelPath);
        $inference = (null !== $mood ? ($config['mood_inference'][$mood] ?? null) : null)
            ?? $config['inference']
            ?? [];

        return is_array($inference) ? $inference : [];
    }

    /**
     * Picker label for a station voice file, e.g. kim_rasmussen/en_US-kim-high with
     * four moods -> "Kim Rasmussen - Auto Mood (warm, calm, upbeat, amused)" and
     * kim_rasmussen/en_US-kim_calm-high -> "Kim Rasmussen - Calm".
     */
    public static function customLabel(string $modelPath): string
    {
        $core = basename($modelPath, '.onnx');
        $core = (string)preg_replace('/^[a-z]{2,3}_[A-Z]{2}-/', '', $core);
        $core = (string)preg_replace('/-(x_low|low|medium|high)$/', '', $core);

        // A voice kept in its own folder is named after it: kim_rasmussen/ -> "Kim Rasmussen".
        $folder = dirname($modelPath);
        $voiceName = in_array($folder, [self::CUSTOM_VOICES_DIR, self::BUNDLED_VOICES_DIR], true)
            ? null
            : self::titleCase(basename($folder));

        $moods = self::moods($modelPath);
        if ($moods !== []) {
            return ($voiceName ?? self::titleCase($core)) . ' - Auto Mood (' . implode(', ', array_keys($moods)) . ')';
        }

        $map = self::config($modelPath)['speaker_id_map'] ?? [];
        $only = is_array($map) && count($map) === 1 ? (string)array_key_first($map) : '';
        if ($only !== '' && str_ends_with($core, '_' . $only)) {
            return ($voiceName ?? self::titleCase(substr($core, 0, -strlen($only) - 1))) . ' - ' . ucfirst($only);
        }

        return $voiceName !== null ? $voiceName . ' - ' . self::titleCase($core) : self::titleCase($core);
    }

    private static function titleCase(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    /** @return array<string, mixed> */
    private static function config(string $modelPath): array
    {
        // Keyed on mtime: workers are long-lived, and a re-uploaded voice must not
        // keep its old config until the next restart.
        $key = $modelPath . '@' . (int)@filemtime($modelPath . '.json');
        if (!isset(self::$configs[$key])) {
            $json = @file_get_contents($modelPath . '.json');
            $decoded = false !== $json ? json_decode($json, true) : null;
            self::$configs[$key] = is_array($decoded) ? $decoded : [];
        }

        return self::$configs[$key];
    }
}
