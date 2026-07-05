<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\AiDj;
use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;
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
    private const int MAX_TTS_CHARS = 500; // Max characters for TTS to prevent timeouts

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
        private readonly AiDjContentRepository $contentRepo,
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
        string $outputPath,
        float $voiceSpeed = 1.0,
        bool $useBackgroundAudio = false
    ): ?string {
        $tempDir = dirname($outputPath);

        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true)) {
            $this->logger->error(sprintf('Failed to create AI DJ directory: %s', $tempDir));
            return null;
        }

        // Truncate long text to prevent TTS timeouts on low-RAM servers
        $text = $this->truncateForTts($text);

        $isKokoro = $voiceModelPath && str_starts_with($voiceModelPath, self::KOKORO_PREFIX);

        if ($isKokoro) {
            $result = $this->generateWithKokoro($text, $voiceModelPath, $outputPath, $tempDir, $voiceSpeed);
        } else {
            $result = $this->generateWithPiper($text, $voiceModelPath, $outputPath, $tempDir, $voiceSpeed);
        }

        if ($result !== null && $useBackgroundAudio) {
            $result = $this->mixWithBackgroundAudio($result, $tempDir);
        }

        return $result;
    }

    private function generateWithKokoro(
        string $text,
        string $voiceModelPath,
        string $outputPath,
        string $tempDir,
        float $voiceSpeed = 1.0
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
                (string) $voiceSpeed,
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

            // Add 2.5s of silence at BOTH ends (regardless of clip length) so the
            // station's crossfade overlaps silence, not speech. Without leading
            // silence the previous song's fade-out buried her first words, and
            // long clips (>6s) previously got no trailing pad, so the next song's
            // fade-in clipped her last words. This keeps the whole clip audible
            // and still exceeds the crossfade analysis window (avoids cross() errors).
            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $wavFile,
                '-af', 'adelay=delays=2500:all=1,apad=pad_dur=2.5',
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
        string $tempDir,
        float $voiceSpeed = 1.0
    ): ?string {
        $modelPath = $voiceModelPath ?: AiNewsGenerator::AVAILABLE_VOICE_MODELS[0]['path'];
        $wavFile = $tempDir . '/audio_' . uniqid() . '.wav';
        $tmpMp3 = $tempDir . '/audio_' . uniqid() . '_tmp.mp3';

        try {
            $piperArgs = [
                self::PIPER_BIN,
                '--model', $modelPath,
                '--output_file', $wavFile,
            ];
            if ($voiceSpeed !== 1.0) {
                $piperArgs[] = '--length_scale';
                $piperArgs[] = (string) (1.0 / $voiceSpeed); // Piper: lower = faster
            }
            $piper = new Process($piperArgs);
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

            // Pad to minimum 6s total to exceed station crossfade duration (3s).
            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $wavFile,
                '-af', 'adelay=delays=2500:all=1,apad=pad_dur=2.5',
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
            ?? $this->selectStationTemplate($station->id, AiDjContent::TYPE_SONG_INTRO_TEMPLATE)
            ?? $this->getDefaultSongIntro();

        $this->logger->info('AI DJ: Song intro metadata', [
            'artist' => $artist,
            'song' => $songTitle,
            'dj' => $dj->getName(),
        ]);

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $this->getSpokenName($dj->getName()),
                'show_name' => $this->getShowName($dj->getName()),
                'artist' => $artist ?? 'this artist',
                'song' => $songTitle ?? 'this song',
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/song_intro_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());
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

        $hasNext = ($nextArtist !== null && $nextArtist !== '');

        $template = $this->selectRandomPostSongTemplate($dj->getContents(), $hasNext)
            ?? $this->selectStationTemplate($station->id, AiDjContent::TYPE_POST_SONG_TEMPLATE)
            ?? $this->getDefaultPostSong($hasNext);

        // Safety net: never let a post-song template mention a "next" song when we
        // don't actually know it. That produced "coming up next, the next song"
        // with no name, followed by an awkward pause. Use a prev-only line instead.
        if (!$hasNext && str_contains($template, '{{next_')) {
            $template = $this->getDefaultPostSong(false);
        }

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $this->getSpokenName($dj->getName()),
                'show_name' => $this->getShowName($dj->getName()),
                'prev_artist' => $prevArtist ?? 'that artist',
                'prev_song' => $prevTitle ?? 'that song',
                'next_artist' => $nextArtist ?? 'the next artist',
                'next_song' => $nextTitle ?? 'the next song',
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/post_song_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());
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
                'dj_name' => $this->getSpokenName($dj->getName()),
                'show_name' => $this->getShowName($dj->getName()),
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/shift_outro_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());
    }

    /**
     * Generate a shift intro (welcome) audio file when a DJ's scheduled block begins.
     *
     * @return string|null MP3 path on success, null on failure
     */
    public function generateShiftIntro(
        AiDj $dj,
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

        $template = $dj->getShiftIntroTemplate()
            ?? 'Hey, this is {{dj_name}} on {{station_name}}. Welcome to the show!';

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $this->getSpokenName($dj->getName()),
                'show_name' => $this->getShowName($dj->getName()),
                'station_name' => $station->name,
            ]
        );

        $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
        $outputPath = $outputDir . '/shift_intro_' . uniqid() . '.mp3';

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());
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

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());
    }

    /**
     * Build spoken text for a content liner based on its type.
     */
    private function buildLinerText(AiDj $dj, AiDjContent $content, Station $station): string
    {
        $djName = $this->getSpokenName($dj->getName());
        $stationName = $station->name;
        $text = $content->content;
        $reference = $content->reference;

        return match ($content->type) {
            AiDjContent::TYPE_BIBLE_VERSE => $reference
                ? sprintf("You're listening to %s with %s. I want to share a scripture with you from %s. %s. Let that truth settle in your heart today.", $stationName, $djName, $reference, $text)
                : sprintf("Here's a word from the Lord for you today, from %s on %s. %s. Stay blessed.", $djName, $stationName, $text),
            AiDjContent::TYPE_JOKE => sprintf("Hey, it's %s here on %s, and I've got a little something to brighten your day. %s. Hope that put a smile on your face!", $djName, $stationName, $text),
            AiDjContent::TYPE_ENCOURAGEMENT => sprintf("This is %s on %s with some words of encouragement for you today. %s. Remember, you are loved and you are not alone.", $djName, $stationName, $text),
            AiDjContent::TYPE_INSPIRATION => sprintf("This is %s on %s, and I want to share something inspiring with you right now. %s.", $djName, $stationName, $text),
            AiDjContent::TYPE_TESTIMONY => sprintf("I'm %s on %s, and I want to share something powerful with you. %s. What an amazing testimony.", $djName, $stationName, $text),
            AiDjContent::TYPE_STORY => sprintf("This is %s on %s, and I've got a story for you. %s.", $djName, $stationName, $text),
            default => sprintf("This is %s on %s, and I want to share something with you. %s.", $djName, $stationName, $text),
        };
    }

    /**
     * Select a random template from station-wide content by type.
     */
    private function selectStationTemplate(int $stationId, string $type): ?string
    {
        $templates = $this->contentRepo->findEnabledByType($stationId, $type);

        if (empty($templates)) {
            return null;
        }

        return $templates[array_rand($templates)]->content;
    }

    /**
     * Get a longer default song intro template for natural-sounding speech.
     */
    private function getDefaultSongIntro(): string
    {
        $defaults = [
            "Hey there, you're listening to {{station_name}} with {{dj_name}}. Coming up next, we've got {{artist}} with {{song}}. I know you're going to love this one, so sit back and let it speak to your heart.",
            "Welcome back to {{station_name}}, I'm {{dj_name}} and I'm so glad you're here with us today. Up next, we have {{artist}} performing {{song}}. This is one of those songs that really lifts your spirit. Here it is.",
            "This is {{dj_name}} on {{station_name}}, and I've got something really special lined up for you right now. {{artist}} is coming up next with {{song}}. Take a moment, let these words wash over you, and be blessed.",
            "You're tuned into {{station_name}} with your host {{dj_name}}. Get ready, because coming up next we have {{artist}} bringing you {{song}}. This one is sure to brighten your day, so turn it up and enjoy.",
            "It's {{dj_name}} here on {{station_name}}, and I am loving being here with you today. Right now, let me introduce our next song. It's {{song}} by {{artist}}. I hope it blesses you as much as it blessed me.",
        ];

        return $defaults[array_rand($defaults)];
    }

    /**
     * Get a longer default post-song template for natural-sounding speech.
     */
    private function getDefaultPostSong(bool $hasNextSong): string
    {
        $defaults = [
            "That was {{prev_artist}} with {{prev_song}}, right here on {{station_name}}. I'm {{dj_name}}, and I hope that song touched your heart today. We've got more great music lined up for you, so don't go anywhere.",
            "You just heard {{prev_song}} by {{prev_artist}} on {{station_name}} with {{dj_name}}. What a beautiful song. If that blessed you today, we've got plenty more where that came from. Stay with us.",
            "That was {{prev_song}} by {{prev_artist}}. I'm {{dj_name}} and you're listening to {{station_name}}. Thank you for being here with us. More uplifting music is on the way, so keep listening.",
            "Beautiful music from {{prev_artist}} right there. {{prev_song}} on {{station_name}} with {{dj_name}}. I love sharing these songs with you. Stay tuned, we've got so much more coming your way.",
        ];

        if ($hasNextSong) {
            $defaults[] = "That was {{prev_artist}} with {{prev_song}}. This is {{dj_name}} on {{station_name}}. Coming up next, we have {{next_artist}} with {{next_song}}. You're going to love this one.";
            $defaults[] = "What a song. {{prev_artist}} with {{prev_song}} right here on {{station_name}}. I'm {{dj_name}} and next up, {{next_artist}} is bringing you {{next_song}}. Stay blessed.";
        }

        return $defaults[array_rand($defaults)];
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
    private function selectRandomPostSongTemplate(Collection $contents, bool $allowNext = true): ?string
    {
        $templates = $contents
            ->filter(fn(AiDjContent $c) => $c->is_enabled && $c->type === AiDjContent::TYPE_POST_SONG_TEMPLATE)
            ->map(fn(AiDjContent $c) => $c->content)
            ->toArray();

        // When the next song is unknown, drop templates that reference it so the
        // DJ never says a hollow "coming up next, the next song".
        if (!$allowNext) {
            $templates = array_filter(
                $templates,
                static fn(string $t): bool => !str_contains($t, '{{next_')
            );
        }

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

    /**
     * The DJ's spoken first name, so scripts say "Bella" instead of the robotic
     * full label "Afternoon DJ - Bella".
     * "Afternoon DJ - Bella" -> "Bella"; "Morning DJ - Adam" -> "Adam".
     */
    public function getSpokenName(string $fullName): string
    {
        if (str_contains($fullName, ' - ')) {
            $parts = explode(' - ', $fullName);
            $last = trim((string)end($parts));
            if ($last !== '') {
                return $last;
            }
        }

        $cleaned = preg_replace(
            '/\b(morning|afternoon|evening|overnight|midday|weekend|night|the)\b|\bDJ\b|\bhost\b/i',
            '',
            $fullName
        );
        $cleaned = trim((string)preg_replace('/\s+/', ' ', (string)$cleaned), " -");

        return $cleaned !== '' ? $cleaned : $fullName;
    }

    /**
     * A natural show name derived from the DJ label, used sparingly in scripts
     * (e.g. "the afternoon show"). Falls back to "the show".
     */
    private function getShowName(string $fullName): string
    {
        if (preg_match('/\b(morning|afternoon|evening|overnight|midday|weekend|night)\b/i', $fullName, $m)) {
            return 'the ' . strtolower($m[1]) . ' show';
        }

        return 'the show';
    }

    /**
     * Mix the DJ voice clip with a soft ambient music bed.
     * Generates a warm chord pad via FFmpeg's sine source, lowers its volume,
     * and mixes it under the voice for a cozy overnight-radio feel.
     */
    private function mixWithBackgroundAudio(string $voicePath, string $tempDir): ?string
    {
        $mixedPath = $tempDir . '/mixed_' . uniqid() . '.mp3';

        try {
            // Get voice duration so the ambient pad matches it
            $probe = new Process([
                self::FFMPEG_BIN, '-i', $voicePath, '-f', 'null', '-',
            ]);
            $probe->setTimeout(5);
            $probe->run();
            $stderr = $probe->getErrorOutput();
            $duration = 10;
            if (preg_match('/Duration:\s*(\d+):(\d+):(\d+)\.(\d+)/', $stderr, $m)) {
                $duration = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3] + 1;
            }

            // If a real music-bed file has been dropped at "<ai_dj dir>/bed.mp3",
            // use it as the bed (looped to cover the clip, low volume so the voice
            // stays clear, gentle fade in/out). Otherwise fall back to a soft
            // synthetic chord pad. Gated per-DJ by use_background_audio, so only
            // DJs with that setting on (e.g. the Overnight DJ) get a bed.
            $bedFile = dirname($voicePath) . '/bed.mp3';

            if (is_file($bedFile)) {
                $fadeOutStart = max(1, $duration - 2);
                $filterGraph = sprintf(
                    '[1:a]volume=0.18,afade=t=in:st=0:d=1.5,afade=t=out:st=%1$d:d=1.5[bed];'
                    . '[0:a][bed]amix=inputs=2:duration=first:dropout_transition=0[out]',
                    $fadeOutStart
                );
                $ffmpeg = new Process([
                    self::FFMPEG_BIN, '-y',
                    '-i', $voicePath,
                    '-stream_loop', '-1', '-i', $bedFile,
                    '-filter_complex', $filterGraph,
                    '-map', '[out]',
                    '-c:a', 'libmp3lame', '-b:a', '192k',
                    $mixedPath,
                ]);
            } else {
                // Synthetic warm D-major chord pad (146.8 + 185 + 220 Hz) with
                // lowpass warmth, mixed under the DJ voice at low volume.
                $filterGraph = sprintf(
                    'sine=frequency=146.83:duration=%1$d,volume=0.04[s1];'
                    . 'sine=frequency=185:duration=%1$d,volume=0.03[s2];'
                    . 'sine=frequency=220:duration=%1$d,volume=0.03[s3];'
                    . '[s1][s2][s3]amix=inputs=3:duration=longest,lowpass=f=1200[pad];'
                    . '[0:a][pad]amix=inputs=2:duration=first:dropout_transition=1[out]',
                    $duration
                );
                $ffmpeg = new Process([
                    self::FFMPEG_BIN, '-y',
                    '-i', $voicePath,
                    '-filter_complex', $filterGraph,
                    '-map', '[out]',
                    '-c:a', 'libmp3lame', '-b:a', '192k',
                    $mixedPath,
                ]);
            }
            $ffmpeg->setTimeout(20);
            $ffmpeg->run();

            if (!$ffmpeg->isSuccessful()) {
                $this->logger->warning(sprintf(
                    'Background audio mix failed: %s',
                    $ffmpeg->getErrorOutput() ?: 'Unknown error'
                ));
                return $voicePath; // Fallback to voice-only
            }

            // Replace voice-only file with mixed version
            @unlink($voicePath);
            if (!@rename($mixedPath, $voicePath)) {
                @unlink($mixedPath);
                return null;
            }

            return $voicePath;
        } catch (Throwable $e) {
            $this->logger->warning(sprintf('Background audio mixing error: %s', $e->getMessage()));
            @unlink($mixedPath);
            return $voicePath; // Fallback to voice-only
        }
    }

    /**
     * Truncate text to MAX_TTS_CHARS, breaking at sentence boundary.
     */
    private function truncateForTts(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_TTS_CHARS) {
            return $text;
        }

        $truncated = mb_substr($text, 0, self::MAX_TTS_CHARS);
        // Break at last sentence-ending punctuation
        $lastPeriod = max(
            (int) mb_strrpos($truncated, '.'),
            (int) mb_strrpos($truncated, '!'),
            (int) mb_strrpos($truncated, '?')
        );

        if ($lastPeriod > 50) {
            return mb_substr($truncated, 0, $lastPeriod + 1);
        }

        return $truncated;
    }
}
