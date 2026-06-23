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
    private const string FFMPEG_BIN = 'ffmpeg';
    private const int TTS_TIMEOUT = 15;
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
        $modelPath = $voiceModelPath ?: AiNewsGenerator::AVAILABLE_VOICE_MODELS[0]['path'];
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
