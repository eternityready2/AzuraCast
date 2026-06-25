<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\AiDj;
use App\Entity\AiDjContent;
use App\Entity\Station;
use Doctrine\Common\Collections\Collection;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use App\Service\AiNewsGenerator;

final class AiDjGenerator
{
    use LoggerAwareTrait;

    private const string PIPER_BIN = 'piper';
    private const string KOKORO_SCRIPT = '/opt/kokoro/kokoro_tts.py';
    private const string FFMPEG_BIN = 'ffmpeg';
    private const int TTS_TIMEOUT = 90;
    private const int DISK_LIMIT_MB = 400; // 80% of 500MB max
    private const string KOKORO_PREFIX = 'kokoro:';

    public const array KOKORO_VOICES = [
        ['name' => 'Adam (Morning Host)',     'id' => 'kokoro:am_adam',    'gender' => 'male',   'style' => 'calm-energetic'],
        ['name' => 'Michael (Warm Male)',      'id' => 'kokoro:am_michael', 'gender' => 'male',   'style' => 'warm'],
        ['name' => 'Eric (Relaxed Male)',      'id' => 'kokoro:am_eric',    'gender' => 'male',   'style' => 'relaxed'],
        ['name' => 'Liam (Smooth Male)',       'id' => 'kokoro:am_liam',    'gender' => 'male',   'style' => 'smooth'],
        ['name' => 'Onyx (Deep Male)',         'id' => 'kokoro:am_onyx',    'gender' => 'male',   'style' => 'deep-calm'],
        ['name' => 'Fenrir (Mature Male)',     'id' => 'kokoro:am_fenrir',  'gender' => 'male',   'style' => 'mature'],
        ['name' => 'Bella (Energetic Female)', 'id' => 'kokoro:af_bella',   'gender' => 'female', 'style' => 'energetic'],
        ['name' => 'Sarah (Bright Female)',    'id' => 'kokoro:af_sarah',   'gender' => 'female', 'style' => 'bright'],
        ['name' => 'Heart (Warm Female)',      'id' => 'kokoro:af_heart',   'gender' => 'female', 'style' => 'warm'],
        ['name' => 'Nova (Dynamic Female)',    'id' => 'kokoro:af_nova',    'gender' => 'female', 'style' => 'dynamic'],
        ['name' => 'Sky (Upbeat Female)',      'id' => 'kokoro:af_sky',     'gender' => 'female', 'style' => 'upbeat'],
        ['name' => 'Nicole (Smooth Female)',   'id' => 'kokoro:af_nicole',  'gender' => 'female', 'style' => 'smooth'],
        ['name' => 'Jessica (Clear Female)',   'id' => 'kokoro:af_jessica', 'gender' => 'female', 'style' => 'clear'],
        ['name' => 'George (British Male)',    'id' => 'kokoro:bm_george',  'gender' => 'male',   'style' => 'british'],
        ['name' => 'Daniel (British Male)',    'id' => 'kokoro:bm_daniel',  'gender' => 'male',   'style' => 'british'],
        ['name' => 'Emma (British Female)',    'id' => 'kokoro:bf_emma',    'gender' => 'female', 'style' => 'british'],
    ];

    public function __construct(
        private readonly AiDjCleanup $cleanup,
    ) {
    }
    /**
     * Generate TTS audio from text using Piper.
     *
     * @return string|null MP3 path on success, null on failure/timeout
     */
    public function generateAudio(
        string $text,
        ?string $voiceModelPath,
        string $outputPath
    ): ?string {
        $tempDir = dirname($outputPath);

        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true)) {
            $this->logger->error(sprintf('Failed to create AI DJ directory: %s', $tempDir));
            return null;
        }

        $isKokoro = $voiceModelPath && str_starts_with($voiceModelPath, self::KOKORO_PREFIX);

        if ($isKokoro) {
            return $this->generateWithKokoro($text, $voiceModelPath, $outputPath, $tempDir);
        }

        return $this->generateWithPiper($text, $voiceModelPath, $outputPath, $tempDir);
    }

    private function generateWithKokoro(
        string $text,
        string $voiceModelPath,
        string $outputPath,
        string $tempDir
    ): ?string {
        $voiceId = substr($voiceModelPath, strlen(self::KOKORO_PREFIX));
        $wavFile = $tempDir . '/audio_' . uniqid() . '.wav';
        $tmpMp3 = $tempDir . '/audio_' . uniqid() . '_tmp.mp3';

        try {
            $kokoro = new Process([
                'python3',
                self::KOKORO_SCRIPT,
                $text,
                $voiceId,
                $wavFile,
            ]);
            $kokoro->setTimeout(self::TTS_TIMEOUT);
            $kokoro->run();

            if (!$kokoro->isSuccessful()) {
                $this->logger->warning(sprintf(
                    'Kokoro TTS failed: %s',
                    $kokoro->getErrorOutput() ?: 'Unknown error'
                ));
                return null;
            }

            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $wavFile,
                '-c:a', 'libmp3lame',
                '-b:a', '192k',
                $tmpMp3,
            ]);
            $ffmpeg->setTimeout(10);
            $ffmpeg->run();

            if (!$ffmpeg->isSuccessful()) {
                $this->logger->warning('FFmpeg conversion failed.');
                return null;
            }

            if (!@rename($tmpMp3, $outputPath)) {
                throw new RuntimeException(sprintf('Failed to move audio to "%s".', $outputPath));
            }

            return $outputPath;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Kokoro AI DJ audio generation failed: %s', $e->getMessage()));
            return null;
        } finally {
            @unlink($wavFile);
            @unlink($tmpMp3);
        }
    }

    private function generateWithPiper(
        string $text,
        ?string $voiceModelPath,
        string $outputPath,
        string $tempDir
    ): ?string {
        $modelPath = $voiceModelPath ?: AiNewsGenerator::AVAILABLE_VOICE_MODELS[0]['path'];
        $wavFile = $tempDir . '/audio_' . uniqid() . '.wav';
        $tmpMp3 = $tempDir . '/audio_' . uniqid() . '_tmp.mp3';

        try {
            $piper = new Process([
                self::PIPER_BIN,
                '--model', $modelPath,
                '--output_file', $wavFile,
            ]);
            $piper->setInput($text);
            $piper->setTimeout(self::TTS_TIMEOUT);
            $piper->run();

            if (!$piper->isSuccessful()) {
                $this->logger->warning(sprintf(
                    'Piper TTS failed or timed out: %s',
                    $piper->getErrorOutput() ?: 'Unknown error'
                ));
                return null;
            }

            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $wavFile,
                '-c:a', 'libmp3lame',
                '-b:a', '128k',
                $tmpMp3,
            ]);
            $ffmpeg->setTimeout(10);
            $ffmpeg->run();

            if (!$ffmpeg->isSuccessful()) {
                $this->logger->warning('FFmpeg conversion failed.');
                return null;
            }

            if (!@rename($tmpMp3, $outputPath)) {
                throw new RuntimeException(sprintf('Failed to move audio to "%s".', $outputPath));
            }

            return $outputPath;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('AI DJ audio generation failed: %s', $e->getMessage()));
            return null;
        } finally {
            @unlink($wavFile);
            @unlink($tmpMp3);
        }
    }

    /**
     * Generate a song intro audio file for the given DJ and track metadata.
     *
     * @return string|null MP3 path on success, null on failure
     */
    public function generateSongIntro(
        AiDj $dj,
        ?string $artist,
        ?string $songTitle,
        Station $station
    ): ?string {
        // Check disk usage before generating
        $usedMb = $this->cleanup->checkDiskUsage($station->id);
        if ($usedMb > self::DISK_LIMIT_MB) {
            $this->logger->warning(sprintf(
                'AI DJ generation skipped: disk usage %dMB exceeds limit of %dMB',
                $usedMb,
                self::DISK_LIMIT_MB
            ));
            return null;
        }

        $template = $this->selectRandomTemplate($dj->getContents())
            ?? 'Coming up next on {{station_name}}.';

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $dj->getName(),
                'artist' => $artist ?? 'this artist',
                'song' => $songTitle ?? 'this song',
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/song_intro_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath);
    }

    /**
     * Generate a post-song wrap-up audio file referencing the song that just played
     * and optionally the next song coming up.
     *
     * @return string|null MP3 path on success, null on failure
     */
    public function generatePostSong(
        AiDj $dj,
        ?string $prevArtist,
        ?string $prevTitle,
        ?string $nextArtist,
        ?string $nextTitle,
        Station $station
    ): ?string {
        $usedMb = $this->cleanup->checkDiskUsage($station->id);
        if ($usedMb > self::DISK_LIMIT_MB) {
            return null;
        }

        $template = $this->selectRandomPostSongTemplate($dj->getContents());

        if (null === $template) {
            // Use built-in defaults if no custom templates exist
            $defaults = [
                'That was {{prev_artist}} with {{prev_song}}. You\'re listening to {{station_name}} with {{dj_name}}.',
                'Hope you enjoyed {{prev_song}} by {{prev_artist}}. Coming up next on {{station_name}}.',
                'That was {{prev_song}} by {{prev_artist}}. Stay tuned to {{station_name}}, more great music coming your way.',
                '{{prev_artist}}, {{prev_song}}. This is {{dj_name}} on {{station_name}}.',
            ];

            if ($nextArtist) {
                $defaults[] = 'That was {{prev_artist}} with {{prev_song}}. Coming up next, {{next_artist}} with {{next_song}}.';
                $defaults[] = '{{prev_artist}}, {{prev_song}}. Next up on {{station_name}}, {{next_artist}}.';
            }

            $template = $defaults[array_rand($defaults)];
        }

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $dj->getName(),
                'prev_artist' => $prevArtist ?? 'that artist',
                'prev_song' => $prevTitle ?? 'that song',
                'next_artist' => $nextArtist ?? 'the next artist',
                'next_song' => $nextTitle ?? 'the next song',
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/post_song_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath);
    }

    /**
     * Generate a shift outro (sign-off) audio file for the given DJ.
     *
     * @return string|null MP3 path on success, null on failure
     */
    public function generateShiftOutro(
        AiDj $dj,
        Station $station
    ): ?string {
        // Check disk usage before generating
        $usedMb = $this->cleanup->checkDiskUsage($station->id);
        if ($usedMb > self::DISK_LIMIT_MB) {
            $this->logger->warning(sprintf(
                'AI DJ generation skipped: disk usage %dMB exceeds limit of %dMB',
                $usedMb,
                self::DISK_LIMIT_MB
            ));
            return null;
        }

        $template = $dj->getShiftOutroTemplate()
            ?? 'This has been {{dj_name}} on {{station_name}}. Thanks for listening!';

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $dj->getName(),
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/shift_outro_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath);
    }

    /**
     * Generate a content liner (bible verse, joke, encouragement, etc.) audio file.
     *
     * @return string|null MP3 path on success, null on failure
     */
    public function generateContentLiner(
        AiDj $dj,
        AiDjContent $content,
        Station $station
    ): ?string {
        $usedMb = $this->cleanup->checkDiskUsage($station->id);
        if ($usedMb > self::DISK_LIMIT_MB) {
            $this->logger->warning(sprintf(
                'AI DJ generation skipped: disk usage %dMB exceeds limit of %dMB',
                $usedMb,
                self::DISK_LIMIT_MB
            ));
            return null;
        }

        $text = $this->buildLinerText($dj, $content, $station);

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/liner_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath);
    }

    /**
     * Build spoken text for a content liner based on its type.
     */
    private function buildLinerText(AiDj $dj, AiDjContent $content, Station $station): string
    {
        $djName = $dj->getName();
        $text = $content->content;
        $reference = $content->reference;

        return match ($content->type) {
            AiDjContent::TYPE_BIBLE_VERSE => $reference
                ? sprintf('%s. %s', $reference, $text)
                : $text,
            AiDjContent::TYPE_JOKE => sprintf("Here's a little something from %s. %s", $djName, $text),
            AiDjContent::TYPE_ENCOURAGEMENT => $text,
            AiDjContent::TYPE_TESTIMONY => sprintf("I want to share this with you. %s", $text),
            AiDjContent::TYPE_STORY => sprintf("Let me tell you something. %s", $text),
            default => $text,
        };
    }

    /**
     * Select a random song_intro_template from the DJ's content collection.
     */
    private function selectRandomTemplate(Collection $contents): ?string
    {
        $templates = $contents
            ->filter(fn(AiDjContent $c) => $c->is_enabled && $c->type === AiDjContent::TYPE_SONG_INTRO_TEMPLATE)
            ->map(fn(AiDjContent $c) => $c->content)
            ->toArray();

        if ([] === $templates) {
            return null;
        }

        return $templates[array_rand($templates)];
    }

    /**
     * Select a random post_song_template from the DJ's content collection.
     */
    private function selectRandomPostSongTemplate(Collection $contents): ?string
    {
        $templates = $contents
            ->filter(fn(AiDjContent $c) => $c->is_enabled && $c->type === AiDjContent::TYPE_POST_SONG_TEMPLATE)
            ->map(fn(AiDjContent $c) => $c->content)
            ->toArray();

        if ([] === $templates) {
            return null;
        }

        return $templates[array_rand($templates)];
    }

    /**
     * Replace {{variable}} placeholders with actual values.
     */
    private function replaceTemplateVariables(string $template, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return strtr($template, $replacements);
    }
}
