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

final class AiDjGenerator
{
    use LoggerAwareTrait;

    private const string PIPER_BIN = '/usr/local/bin/piper';
    private const string FFMPEG_BIN = 'ffmpeg';
    private const string DEFAULT_VOICE_MODEL =
        '/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx';
    private const int TTS_TIMEOUT = 3;
    private const int DISK_LIMIT_MB = 400; // 80% of 500MB max

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
        $modelPath = $voiceModelPath ?: self::DEFAULT_VOICE_MODEL;
        $tempDir = dirname($outputPath);

        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true)) {
            $this->logger->error(sprintf('Failed to create AI DJ directory: %s', $tempDir));
            return null;
        }

        $scriptFile = $tempDir . '/script_' . uniqid() . '.txt';
        $wavFile = $tempDir . '/audio_' . uniqid() . '.wav';
        $tmpMp3 = $tempDir . '/audio_' . uniqid() . '_tmp.mp3';

        try {
            if (false === file_put_contents($scriptFile, $text)) {
                throw new RuntimeException('Failed to write TTS script file.');
            }

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
            @unlink($scriptFile);
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
        $usedMb = $this->cleanup->checkDiskUsage($station->getId());
        if ($usedMb > self::DISK_LIMIT_MB) {
            $this->logger->warning(sprintf(
                'AI DJ generation skipped: disk usage %dMB exceeds limit of %dMB',
                $usedMb,
                self::DISK_LIMIT_MB
            ));
            return null;
        }

        $template = $this->selectRandomTemplate($dj->getContents());

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $dj->getName(),
                'artist' => $artist ?? 'this artist',
                'song' => $songTitle ?? 'this song',
                'station_name' => $station->getName(),
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->getId() . '/ai_dj';
        $outputPath = $outputDir . '/song_intro_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath);
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
