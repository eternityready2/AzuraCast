<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\AiDj;
use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;
use App\Entity\Station;
use App\Radio\AutoDJ\AiDjTalkRules;
use Doctrine\Common\Collections\Collection;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

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

    /** A break shorter than this gets no music bed: the music would run longer than the talk. */
    private const float BED_MIN_VOICE_SECONDS = 8.0;

    /** Bed level under the voice, against its level in the lead-in and tail (about -16 dB). */
    private const float BED_DUCK_GAIN = 0.15;

    /** Loudness the bed is brought to before the levels are set, so a quiet passage still reads as music. */
    private const float BED_LUFS = -18.0;

    // Per-segment character budget for a two-part combo break. 2*230 + a joining
    // space = 461 < MAX_TTS_CHARS (500), so a combined clip never hits the cap.
    private const int COMBO_SEGMENT_CHARS = 230;

    // Full Kokoro v1.0 voice set (all speakers in voices-v1.0.bin).
    public const array KOKORO_VOICES = [
        // American English — female
        ['name' => 'Alloy (American Female)', 'id' => 'kokoro:af_alloy', 'gender' => 'female', 'style' => 'neutral'],
        ['name' => 'Aoede (American Female)', 'id' => 'kokoro:af_aoede', 'gender' => 'female', 'style' => 'expressive'],
        ['name' => 'Bella (Energetic Female)', 'id' => 'kokoro:af_bella', 'gender' => 'female', 'style' => 'energetic'],
        ['name' => 'Heart (Warm Female)', 'id' => 'kokoro:af_heart', 'gender' => 'female', 'style' => 'warm'],
        ['name' => 'Jessica (Clear Female)', 'id' => 'kokoro:af_jessica', 'gender' => 'female', 'style' => 'clear'],
        ['name' => 'Kore (American Female)', 'id' => 'kokoro:af_kore', 'gender' => 'female', 'style' => 'neutral'],
        ['name' => 'Nicole (Smooth Female)', 'id' => 'kokoro:af_nicole', 'gender' => 'female', 'style' => 'smooth'],
        ['name' => 'Nova (Dynamic Female)', 'id' => 'kokoro:af_nova', 'gender' => 'female', 'style' => 'dynamic'],
        ['name' => 'River (American Female)', 'id' => 'kokoro:af_river', 'gender' => 'female', 'style' => 'soft'],
        ['name' => 'Sarah (Bright Female)', 'id' => 'kokoro:af_sarah', 'gender' => 'female', 'style' => 'bright'],
        ['name' => 'Sky (Upbeat Female)', 'id' => 'kokoro:af_sky', 'gender' => 'female', 'style' => 'upbeat'],
        // American English — male
        ['name' => 'Adam (Morning Host)', 'id' => 'kokoro:am_adam', 'gender' => 'male', 'style' => 'calm-energetic'],
        ['name' => 'Echo (American Male)', 'id' => 'kokoro:am_echo', 'gender' => 'male', 'style' => 'neutral'],
        ['name' => 'Eric (Relaxed Male)', 'id' => 'kokoro:am_eric', 'gender' => 'male', 'style' => 'relaxed'],
        ['name' => 'Fenrir (Mature Male)', 'id' => 'kokoro:am_fenrir', 'gender' => 'male', 'style' => 'mature'],
        ['name' => 'Liam (Smooth Male)', 'id' => 'kokoro:am_liam', 'gender' => 'male', 'style' => 'smooth'],
        ['name' => 'Michael (Warm Male)', 'id' => 'kokoro:am_michael', 'gender' => 'male', 'style' => 'warm'],
        ['name' => 'Onyx (Deep Male)', 'id' => 'kokoro:am_onyx', 'gender' => 'male', 'style' => 'deep-calm'],
        ['name' => 'Puck (American Male)', 'id' => 'kokoro:am_puck', 'gender' => 'male', 'style' => 'playful'],
        ['name' => 'Santa (American Male)', 'id' => 'kokoro:am_santa', 'gender' => 'male', 'style' => 'festive'],
        // British English — female / male
        ['name' => 'Alice (British Female)', 'id' => 'kokoro:bf_alice', 'gender' => 'female', 'style' => 'british'],
        ['name' => 'Emma (British Female)', 'id' => 'kokoro:bf_emma', 'gender' => 'female', 'style' => 'british'],
        ['name' => 'Isabella (British Female)', 'id' => 'kokoro:bf_isabella', 'gender' => 'female', 'style' => 'british'],
        ['name' => 'Lily (British Female)', 'id' => 'kokoro:bf_lily', 'gender' => 'female', 'style' => 'british'],
        ['name' => 'Daniel (British Male)', 'id' => 'kokoro:bm_daniel', 'gender' => 'male', 'style' => 'british'],
        ['name' => 'Fable (British Male)', 'id' => 'kokoro:bm_fable', 'gender' => 'male', 'style' => 'british'],
        ['name' => 'George (British Male)', 'id' => 'kokoro:bm_george', 'gender' => 'male', 'style' => 'british'],
        ['name' => 'Lewis (British Male)', 'id' => 'kokoro:bm_lewis', 'gender' => 'male', 'style' => 'british'],
        // Other languages shipped in voices-v1.0.bin
        ['name' => 'Dora (Spanish Female)', 'id' => 'kokoro:ef_dora', 'gender' => 'female', 'style' => 'spanish'],
        ['name' => 'Alex (Spanish Male)', 'id' => 'kokoro:em_alex', 'gender' => 'male', 'style' => 'spanish'],
        ['name' => 'Siwis (French Female)', 'id' => 'kokoro:ff_siwis', 'gender' => 'female', 'style' => 'french'],
        ['name' => 'Alpha (Hindi Female)', 'id' => 'kokoro:hf_alpha', 'gender' => 'female', 'style' => 'hindi'],
        ['name' => 'Beta (Hindi Female)', 'id' => 'kokoro:hf_beta', 'gender' => 'female', 'style' => 'hindi'],
        ['name' => 'Omega (Hindi Male)', 'id' => 'kokoro:hm_omega', 'gender' => 'male', 'style' => 'hindi'],
        ['name' => 'Psi (Hindi Male)', 'id' => 'kokoro:hm_psi', 'gender' => 'male', 'style' => 'hindi'],
        ['name' => 'Sara (Italian Female)', 'id' => 'kokoro:if_sara', 'gender' => 'female', 'style' => 'italian'],
        ['name' => 'Nicola (Italian Male)', 'id' => 'kokoro:im_nicola', 'gender' => 'male', 'style' => 'italian'],
        ['name' => 'Alpha (Japanese Female)', 'id' => 'kokoro:jf_alpha', 'gender' => 'female', 'style' => 'japanese'],
        ['name' => 'Gongitsune (Japanese Female)', 'id' => 'kokoro:jf_gongitsune', 'gender' => 'female', 'style' => 'japanese'],
        ['name' => 'Nezumi (Japanese Female)', 'id' => 'kokoro:jf_nezumi', 'gender' => 'female', 'style' => 'japanese'],
        ['name' => 'Tebukuro (Japanese Female)', 'id' => 'kokoro:jf_tebukuro', 'gender' => 'female', 'style' => 'japanese'],
        ['name' => 'Kumo (Japanese Male)', 'id' => 'kokoro:jm_kumo', 'gender' => 'male', 'style' => 'japanese'],
        ['name' => 'Dora (Portuguese Female)', 'id' => 'kokoro:pf_dora', 'gender' => 'female', 'style' => 'portuguese'],
        ['name' => 'Alex (Portuguese Male)', 'id' => 'kokoro:pm_alex', 'gender' => 'male', 'style' => 'portuguese'],
        ['name' => 'Santa (Portuguese Male)', 'id' => 'kokoro:pm_santa', 'gender' => 'male', 'style' => 'portuguese'],
        ['name' => 'Xiaobei (Chinese Female)', 'id' => 'kokoro:zf_xiaobei', 'gender' => 'female', 'style' => 'chinese'],
        ['name' => 'Xiaoni (Chinese Female)', 'id' => 'kokoro:zf_xiaoni', 'gender' => 'female', 'style' => 'chinese'],
        ['name' => 'Xiaoxiao (Chinese Female)', 'id' => 'kokoro:zf_xiaoxiao', 'gender' => 'female', 'style' => 'chinese'],
        ['name' => 'Xiaoyi (Chinese Female)', 'id' => 'kokoro:zf_xiaoyi', 'gender' => 'female', 'style' => 'chinese'],
        ['name' => 'Yunjian (Chinese Male)', 'id' => 'kokoro:zm_yunjian', 'gender' => 'male', 'style' => 'chinese'],
        ['name' => 'Yunxi (Chinese Male)', 'id' => 'kokoro:zm_yunxi', 'gender' => 'male', 'style' => 'chinese'],
        ['name' => 'Yunxia (Chinese Male)', 'id' => 'kokoro:zm_yunxia', 'gender' => 'male', 'style' => 'chinese'],
        ['name' => 'Yunyang (Chinese Male)', 'id' => 'kokoro:zm_yunyang', 'gender' => 'male', 'style' => 'chinese'],
    ];

    /**
     * A real host names herself and the station now and then, not every break
     * (interval set on the AI DJ page, see AiDjTalkRules). Breaks inside that
     * window use lines without {{dj_name}} / {{station_name}}.
     */
    private const int IDENT_CACHE_TTL_SECONDS = 3 * 3600;

    private const array IDENT_TOKENS = ['{{dj_name}}', '{{station_name}}', '{{show_name}}'];

    /**
     * Delivery moods of a Piper mood voice (e.g. Kim Rasmussen's four styles).
     * Each break picks the mood that fits its script; voices without moods
     * (Kokoro, single-style Piper) ignore it.
     */
    public const string MOOD_WARM = 'warm';
    public const string MOOD_CALM = 'calm';
    public const string MOOD_UPBEAT = 'upbeat';
    public const string MOOD_AMUSED = 'amused';

    public static function moodForContentType(string $type): string
    {
        return match ($type) {
            AiDjContent::TYPE_BIBLE_VERSE => self::MOOD_CALM,
            AiDjContent::TYPE_JOKE => self::MOOD_AMUSED,
            default => self::MOOD_WARM,
        };
    }

    public function __construct(
        private readonly AiDjCleanup $cleanup,
        private readonly AiDjContentRepository $contentRepo,
        private readonly CacheInterface $cache,
        private readonly AiDjMusicBed $musicBed,
    ) {
    }

    /**
     * The DJ's music bed for one kind of break, or null when that break is
     * spoken dry: the bed is off, no file is uploaded, or the break is not one
     * the DJ's settings name. $break is an AiDj::BED_* key or a content type.
     *
     * @param list<string|null> $breaks Any of them enabled is enough (a combo has two halves).
     * @return array{path: string, lead_in: float, tail: float, from_start: bool, min_voice: float}|null
     */
    private function bedFor(AiDj $dj, array $breaks): ?array
    {
        $path = $dj->getBackgroundAudioPath();
        if (!$dj->useBackgroundAudio() || null === $path || !is_file($path)) {
            return null;
        }

        $enabled = array_intersect($dj->getBackgroundAudioBreaks(), $breaks);
        if ([] === $enabled) {
            return null;
        }

        // The shift opens on the top of the piece and lets it play before the
        // first word, the way the DJ's own recorded shows did (he came in 22 to
        // 29 seconds into the music; 6 seconds had him talking too soon). It
        // closes with the music carrying on after the sign-off.
        if (in_array(AiDj::BED_SHIFT_INTRO, $enabled, true)) {
            return ['path' => $path, 'lead_in' => 22.0, 'tail' => 4.0, 'from_start' => true, 'min_voice' => 0.0];
        }
        if (in_array(AiDj::BED_SHIFT_OUTRO, $enabled, true)) {
            return ['path' => $path, 'lead_in' => 12.0, 'tail' => 8.0, 'from_start' => false, 'min_voice' => 0.0];
        }

        // Anywhere else the music must not outlast the talking.
        return [
            'path' => $path,
            'lead_in' => 10.0,
            'tail' => 3.5,
            'from_start' => false,
            'min_voice' => self::BED_MIN_VOICE_SECONDS,
        ];
    }

    /** The old synthetic pad: still what a DJ with the bed on and no file uploaded gets. */
    public function usesSyntheticPad(AiDj $dj): bool
    {
        $path = $dj->getBackgroundAudioPath();

        return $dj->useBackgroundAudio() && (null === $path || !is_file($path));
    }

    /**
     * True (and recorded) when this break should say the DJ's name and the
     * station; false while a recent break already did.
     */
    public function claimIdentification(Station $station, AiDj $dj): bool
    {
        $interval = AiDjTalkRules::identIntervalSeconds($station);
        $key = $this->identKey($station, $dj);
        $last = (int)($this->cache->get($key) ?? 0);
        if ($interval > 0 && $last > 0 && (time() - $last) < $interval) {
            return false;
        }

        $this->markIdentified($station, $dj);
        return true;
    }

    public function markIdentified(Station $station, AiDj $dj): void
    {
        $this->cache->set($this->identKey($station, $dj), time(), self::IDENT_CACHE_TTL_SECONDS);
    }

    private function identKey(Station $station, AiDj $dj): string
    {
        return 'ai_dj_last_ident_' . $station->id . '_' . $dj->getId();
    }

    private static function isIdentFree(string $template): bool
    {
        foreach (self::IDENT_TOKENS as $token) {
            if (str_contains($template, $token)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True if the station's AI DJ clip storage is over its quota and generation
     * should be skipped.
     */
    private function isOverDiskQuota(Station $station, bool $logWarning = true): bool
    {
        $usedMb = $this->cleanup->checkDiskUsage($station->id);
        if ($usedMb <= self::DISK_LIMIT_MB) {
            return false;
        }

        if ($logWarning) {
            $this->logger->warning(sprintf(
                'AI DJ generation skipped: disk usage %dMB exceeds limit of %dMB',
                $usedMb,
                self::DISK_LIMIT_MB
            ));
        }

        return true;
    }

    private function getClipDirectory(Station $station): string
    {
        return '/var/azuracast/stations/' . $station->id . '/ai_dj';
    }

    private function buildClipOutputPath(Station $station, string $filenamePrefix): string
    {
        return $this->getClipDirectory($station) . '/' . $filenamePrefix . '_' . uniqid() . '.mp3';
    }

    /**
     * Generate TTS audio from text using Piper or Kokoro, depending on the voice model.
     * $mood (MOOD_*) picks the delivery on a Piper mood voice; other voices ignore it.
     * $bed (see bedFor()) puts the DJ's music around and under the voice.
     *
     * @param array{path: string, lead_in: float, tail: float, from_start: bool, min_voice: float}|null $bed
     * @return string|null MP3 path on success, null on failure/timeout
     */

    public function generateAudio(
        string $text,
        ?string $voiceModelPath,
        string $outputPath,
        float $voiceSpeed = 1.0,
        bool $useBackgroundAudio = false,
        ?string $mood = null,
        ?array $bed = null,
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
            $result = $this->generateWithPiper($text, $voiceModelPath, $outputPath, $tempDir, $voiceSpeed, $mood);
        }

        if ($result !== null && null !== $bed) {
            $result = $this->mixWithMusicBed($result, $bed);
        } elseif ($result !== null && $useBackgroundAudio) {
            $result = $this->mixWithBackgroundAudio($result, $tempDir);
        }

        return $result;
    }

    private function renderKokoroWav(
        string $text,
        string $voiceModelPath,
        string $wavFile,
        float $voiceSpeed
    ): bool {
        $voiceId = substr($voiceModelPath, strlen(self::KOKORO_PREFIX));

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
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Kokoro AI DJ audio generation failed: %s', $e->getMessage()));
            return false;
        }
    }

    // Renders either a Piper or Kokoro voice to a raw wav, picked by the voice path's prefix.
    private function renderPieceWav(
        string $text,
        ?string $voiceModelPath,
        string $wavFile,
        float $voiceSpeed,
        ?string $mood
    ): bool {
        if ($voiceModelPath !== null && str_starts_with($voiceModelPath, self::KOKORO_PREFIX)) {
            return $this->renderKokoroWav($text, $voiceModelPath, $wavFile, $voiceSpeed);
        }

        return $this->renderPiperWav($text, $voiceModelPath, $wavFile, $voiceSpeed, $mood);
    }

    private function generateWithKokoro(
        string $text,
        string $voiceModelPath,
        string $outputPath,
        string $tempDir,
        float $voiceSpeed = 1.0
    ): ?string {
        $wavFile = $tempDir . '/audio_' . uniqid() . '.wav';
        $tmpMp3 = $tempDir . '/audio_' . uniqid() . '_tmp.mp3';

        try {
            if (!$this->renderKokoroWav($text, $voiceModelPath, $wavFile, $voiceSpeed)) {
                return null;
            }

            return $this->finalizeAudioClip($wavFile, $tmpMp3, $outputPath, '192k');
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
        float $voiceSpeed = 1.0,
        ?string $mood = null
    ): ?string {
        $wavFile = $tempDir . '/audio_' . uniqid() . '.wav';
        $tmpMp3 = $tempDir . '/audio_' . uniqid() . '_tmp.mp3';

        try {
            if (!$this->renderPiperWav($text, $voiceModelPath, $wavFile, $voiceSpeed, $mood)) {
                return null;
            }

            return $this->finalizeAudioClip($wavFile, $tmpMp3, $outputPath, '128k');
        } catch (Throwable $e) {
            $this->logger->error(sprintf('AI DJ audio generation failed: %s', $e->getMessage()));
            return null;
        } finally {
            @unlink($wavFile);
            @unlink($tmpMp3);
        }
    }

    /**
     * Render raw Piper speech to a wav. On a mood voice the mood selects the
     * speaker and that mood's own pace/expression from the voice's config.
     */
    private function renderPiperWav(
        string $text,
        ?string $voiceModelPath,
        string $wavFile,
        float $voiceSpeed,
        ?string $mood
    ): bool {
        $modelPath = $voiceModelPath
            ?: (AiNewsGenerator::getAvailableVoiceModels()[0]['path'] ?? null);

        if (null === $modelPath) {
            $this->logger->error('Piper TTS failed: no Piper voice model is installed.');
            return false;
        }

        $piperArgs = [
            self::PIPER_BIN,
            '--model', $modelPath,
            '--output_file', $wavFile,
        ];

        $moods = PiperVoices::moods((string)$modelPath);
        $mood = isset($moods[$mood ?? '']) ? $mood : null;
        if (null !== $mood) {
            $inference = PiperVoices::inference((string)$modelPath, $mood);
            $piperArgs[] = '--speaker';
            $piperArgs[] = (string)$moods[$mood];
            foreach (['noise_scale' => '--noise_scale', 'noise_w' => '--noise_w'] as $key => $flag) {
                if (isset($inference[$key])) {
                    $piperArgs[] = $flag;
                    $piperArgs[] = (string)$inference[$key];
                }
            }
            $piperArgs[] = '--length_scale';
            $piperArgs[] = (string)((float)($inference['length_scale'] ?? 1.0) / $voiceSpeed);
        } elseif ($voiceSpeed !== 1.0) {
            $piperArgs[] = '--length_scale';
            $piperArgs[] = (string) (1.0 / $voiceSpeed); // Piper: lower length_scale = faster speech
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
            return false;
        }

        return true;
    }

    /**
     * Normalize, pad, and encode a raw TTS wav file into the final mp3 clip.
     *
     * Silence is added at both ends so the station's crossfade overlaps silence
     * rather than speech, and loudness is normalized so DJ clips sit at a
     * consistent volume relative to music. Shared by both TTS engines.
     */
    private function finalizeAudioClip(string $wavFile, string $tmpMp3, string $outputPath, string $bitrate): ?string
    {
        $ffmpeg = new Process([
            self::FFMPEG_BIN,
            '-y',
            '-i', $wavFile,
            '-af', 'acompressor=threshold=-20dB:ratio=4:attack=5:release=120,loudnorm=I=-12:TP=-1.5:LRA=8,adelay=delays=800:all=1,apad=pad_dur=2.0',
            '-c:a', 'libmp3lame',
            '-b:a', $bitrate,
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
        if ($this->isOverDiskQuota($station)) {
            return null;
        }

        $identFree = !$this->claimIdentification($station, $dj);
        $template = $this->selectRandomTemplate($dj->getContents(), $identFree)
            ?? $this->selectStationTemplate($station->id, AiDjContent::TYPE_SONG_INTRO_TEMPLATE, $identFree)
            ?? $this->getDefaultSongIntro($identFree);

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

        $outputPath = $this->buildClipOutputPath($station, 'song_intro');

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), self::MOOD_WARM, $this->bedFor($dj, [AiDj::BED_SONG_TALK]));
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
        if ($this->isOverDiskQuota($station, logWarning: false)) {
            return null;
        }

        $text = $this->buildPostSongText(
            $dj,
            $prevArtist,
            $prevTitle,
            $nextArtist,
            $nextTitle,
            $station,
            $this->claimIdentification($station, $dj),
        );

        $outputPath = $this->buildClipOutputPath($station, 'post_song');

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), self::MOOD_WARM, $this->bedFor($dj, [AiDj::BED_SONG_TALK]));
    }

    /**
     * Assemble the spoken post-song text (no rendering). Extracted so a combo
     * break can use it as its intro-bearing first segment without generating a
     * separate audio clip. Behavior identical to the previous inline block.
     */
    public function buildPostSongText(
        AiDj $dj,
        ?string $prevArtist,
        ?string $prevTitle,
        ?string $nextArtist,
        ?string $nextTitle,
        Station $station,
        bool $identify = true,
    ): string {
        $hasNext = ($nextArtist !== null && $nextArtist !== '');
        $identFree = !$identify;

        $template = $this->selectRandomPostSongTemplate($dj->getContents(), $hasNext, $identFree)
            ?? $this->selectStationTemplate($station->id, AiDjContent::TYPE_POST_SONG_TEMPLATE, $identFree)
            ?? $this->getDefaultPostSong($hasNext, $identFree);

        // Safety net: never let a post-song template mention a "next" song when we
        // don't actually know it. That produced "coming up next, the next song"
        // with no name, followed by an awkward pause. Use a prev-only line instead.
        if (!$hasNext && str_contains($template, '{{next_')) {
            $template = $this->getDefaultPostSong(false, $identFree);
        }

        return $this->replaceTemplateVariables(
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
        if ($this->isOverDiskQuota($station)) {
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

        $outputPath = $this->buildClipOutputPath($station, 'shift_outro');

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), self::MOOD_WARM, $this->bedFor($dj, [AiDj::BED_SHIFT_OUTRO]));
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
        if ($this->isOverDiskQuota($station)) {
            return null;
        }

        $template = $dj->getShiftIntroTemplate()
            ?? 'Hey, this is {{dj_name}} on {{station_name}}. Welcome to the show!';
        $this->markIdentified($station, $dj);

        $text = $this->replaceTemplateVariables(
            $template,
            [
                'dj_name' => $this->getSpokenName($dj->getName()),
                'show_name' => $this->getShowName($dj->getName()),
                'station_name' => $station->name,
            ]
        );

        $outputPath = $this->buildClipOutputPath($station, 'shift_intro');

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), self::MOOD_WARM, $this->bedFor($dj, [AiDj::BED_SHIFT_INTRO]));
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
        if ($this->isOverDiskQuota($station)) {
            return null;
        }

        $text = $this->buildLinerText($dj, $content, $station, $this->claimIdentification($station, $dj));

        $outputPath = $this->buildClipOutputPath($station, 'liner');

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), self::moodForContentType($content->type), $this->bedFor($dj, [$content->type]));
    }

    /**
     * A brief 5-10 second between-songs line, so not every break is a full segment.
     */
    public function generateShortLiner(AiDj $dj, Station $station): ?string
    {
        if ($this->isOverDiskQuota($station, logWarning: false)) {
            return null;
        }

        $lines = $this->claimIdentification($station, $dj)
            ? [
                "It's {{dj_name}} here on {{station_name}}. More great music ahead.",
                "{{dj_name}} with you on {{station_name}}. Stay right here.",
                "You're listening to {{station_name}}, I'm {{dj_name}}. Let's keep it going.",
            ]
            : [
                "Stay with us, more great music is on the way.",
                "So glad you're here. Let's keep the music going.",
                "More uplifting music coming right up.",
                "Here's another one for you.",
            ];

        $text = $this->replaceTemplateVariables(
            $lines[array_rand($lines)],
            [
                'dj_name' => $this->getSpokenName($dj->getName()),
                'station_name' => $station->name,
            ]
        );

        $outputPath = $this->buildClipOutputPath($station, 'short_liner');

        return $this->generateAudio($text, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), self::MOOD_WARM, $this->bedFor($dj, [AiDj::BED_SHORT_LINER]));
    }

    /**
     * Render a "combo break": two already-built text segments joined into one TTS
     * clip. Segment 1 carries the single self-intro; segment 2 is intro-free. One
     * render, one mp3 (no ffmpeg concat, so no mid-clip dead air). An empty payload
     * degrades cleanly to a valid single-segment clip.
     *
     * On a Piper mood voice whose two segments want different moods (song
     * commentary warm, then a joke amused), each segment is rendered in its own
     * mood and the two wavs are joined with a short breath before encoding.
     *
     * @param list<string> $contentTypes What the two halves are, for the music bed.
     */
    public function generateComboBreak(
        AiDj $dj,
        string $introText,
        string $payloadText,
        Station $station,
        ?string $introMood = null,
        ?string $payloadMood = null,
        array $contentTypes = [],
    ): ?string {
        // $contentTypes: what the two halves are, for the music bed; a half
        // that talks about the song counts as song talk.
        $bed = $this->bedFor($dj, [] === $contentTypes ? [AiDj::BED_SONG_TALK] : $contentTypes);

        if ($this->isOverDiskQuota($station, logWarning: false)) {
            return null;
        }

        $intro = $this->truncateForTts(trim($introText), self::COMBO_SEGMENT_CHARS);
        $payload = trim($payloadText);
        $payload = $payload === '' ? '' : $this->truncateForTts($payload, self::COMBO_SEGMENT_CHARS);

        if ($intro === '' && $payload === '') {
            return null;
        }

        $outputPath = $this->buildClipOutputPath($station, 'combo');
        $voice = $dj->getVoiceModelPath();

        // Each half is rendered as its own TTS pass and joined with a brief pause,
        // rather than spoken as one continuous line. Gluing two unrelated sentences
        // into a single TTS call produced an audible stretched/held word right at
        // the seam between them.
        if ($payload !== '') {
            $result = $this->renderTwoPieceCombo($dj, $intro, $introMood, $payload, $payloadMood, $outputPath);
            if ($result !== null) {
                if (null !== $bed) {
                    return $this->mixWithMusicBed($result, $bed);
                }

                return $this->usesSyntheticPad($dj)
                    ? $this->mixWithBackgroundAudio($result, dirname($outputPath))
                    : $result;
            }
            // Fall back to one combined render rather than losing the break.
        }

        $combined = $payload === '' ? $intro : $intro . ' ' . $payload;
        return $this->generateAudio($combined, $voice, $outputPath, $dj->getVoiceSpeed(), $this->usesSyntheticPad($dj), $introMood, $bed);
    }

    private function renderTwoPieceCombo(
        AiDj $dj,
        string $intro,
        ?string $introMood,
        string $payload,
        ?string $payloadMood,
        string $outputPath,
    ): ?string {
        $tempDir = dirname($outputPath);
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true)) {
            return null;
        }

        $id = uniqid();
        $first = $tempDir . '/combo_a_' . $id . '.wav';
        $second = $tempDir . '/combo_b_' . $id . '.wav';
        $joined = $tempDir . '/combo_' . $id . '.wav';
        $tmpMp3 = $tempDir . '/combo_' . $id . '_tmp.mp3';

        try {
            $voice = $dj->getVoiceModelPath();
            $speed = $dj->getVoiceSpeed();
            if (
                !$this->renderPieceWav($intro, $voice, $first, $speed, $introMood)
                || !$this->renderPieceWav($payload, $voice, $second, $speed, $payloadMood)
            ) {
                return null;
            }

            // Both halves come from the same voice, so sample rate and format match
            // and the wavs concatenate cleanly; 0.35s is a natural breath between them.
            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $first,
                '-i', $second,
                '-filter_complex', '[0:a]apad=pad_dur=0.35[a];[a][1:a]concat=n=2:v=0:a=1',
                $joined,
            ]);
            $ffmpeg->setTimeout(10);
            $ffmpeg->run();

            if (!$ffmpeg->isSuccessful()) {
                $this->logger->warning('AI DJ: Joining a combo break failed.');
                return null;
            }

            return $this->finalizeAudioClip($joined, $tmpMp3, $outputPath, '128k');
        } catch (Throwable $e) {
            $this->logger->error(sprintf('AI DJ combo break failed: %s', $e->getMessage()));
            return null;
        } finally {
            @unlink($first);
            @unlink($second);
            @unlink($joined);
            @unlink($tmpMp3);
        }
    }

    /**
     * Bible reference as the voice should say it: TTS reads "19:6" as
     * "19 colon 6" and "II" as letters, so "II Kings 19:6" becomes
     * "Second Kings, chapter 19, verse 6" and "Psalms 76:1" becomes
     * "Psalm 76, verse 1". Anything it doesn't recognise is returned as is.
     */
    public static function spokenBibleReference(string $reference): string
    {
        $reference = trim((string)preg_replace('/\s+/', ' ', $reference));
        if (
            !preg_match(
                '/^(?:(I{1,3}|[1-3])(?:st|nd|rd)?\s+)?([A-Za-z][A-Za-z ]*?)\s+(\d+)'
                . '(?::(\d+)(?:\s*[-\x{2013}]\s*(\d+))?)?$/u',
                $reference,
                $m
            )
        ) {
            return $reference;
        }

        $ordinals = [
            'I' => 'First', 'II' => 'Second', 'III' => 'Third',
            '1' => 'First', '2' => 'Second', '3' => 'Third',
        ];
        $book = $m[2];
        if ('' !== $m[1]) {
            $book = $ordinals[$m[1]] . ' ' . $book;
        }
        $book = (string)preg_replace('/^Revelation of John$/i', 'Revelation', $book);

        $chapter = $m[3];
        $verse = $m[4] ?? '';
        $verseEnd = $m[5] ?? '';

        // Psalms are numbered, not chaptered: "Psalm 23", "Psalm 76, verse 1".
        if (preg_match('/^Psalms?$/i', $book)) {
            $spoken = 'Psalm ' . $chapter;
        } else {
            $spoken = $book . ', chapter ' . $chapter;
        }

        if ('' === $verse) {
            return $spoken;
        }

        return '' !== $verseEnd
            ? sprintf('%s, verses %s through %s', $spoken, $verse, $verseEnd)
            : sprintf('%s, verse %s', $spoken, $verse);
    }

    /**
     * Scripture readings only, right before Piper: "1 Chronicles" -> "First
     * Chronicles", and no comma before "and" / "verse", which Piper reads as a
     * long pause. Nothing else in the text is changed.
     */
    public static function cleanScriptureForTts(string $text): string
    {
        $text = (string)preg_replace_callback(
            '/\b([123])\s+(Samuel|Kings|Chronicles|Corinthians|Thessalonians|Timothy|Peter|John)\b/',
            static fn(array $m): string => ['First', 'Second', 'Third'][(int)$m[1] - 1] . ' ' . $m[2],
            $text
        );

        return (string)preg_replace('/,\s+(and|verse)\b/', ' $1', $text);
    }

    /**
     * True when a liner's whole spoken text fits one segment of a combo break,
     * so generateComboBreak() airs it without cutting it.
     */
    public function fitsComboSegment(AiDj $dj, AiDjContent $content, Station $station, bool $includeIntro): bool
    {
        $text = trim($this->buildLinerText($dj, $content, $station, $includeIntro));

        return mb_strlen($text) <= self::COMBO_SEGMENT_CHARS;
    }

    /**
     * Build spoken text for a content liner based on its type.
     */
    public function buildLinerText(AiDj $dj, AiDjContent $content, Station $station, bool $includeIntro = true): string
    {
        $djName = $this->getSpokenName($dj->getName());
        $stationName = $station->name;
        $text = $content->content;
        $reference = $content->reference ? self::spokenBibleReference($content->reference) : null;

        if (!$includeIntro) {
            // Intro-free variant for the second segment of a combo break: payload
            // only, with a light connector and no "This is <dj> on <station>" prefix,
            // so the DJ never re-introduces herself mid-conversation.
            return match ($content->type) {
                AiDjContent::TYPE_BIBLE_VERSE => self::cleanScriptureForTts($reference
                    ? sprintf("Here's a scripture from %s. %s. Let that truth settle in your heart today.", $reference, $text)
                    : sprintf("%s. Stay blessed.", $text)),
                AiDjContent::TYPE_JOKE => sprintf("Here's a little something to brighten your day. %s. Hope that put a smile on your face!", $text),
                AiDjContent::TYPE_ENCOURAGEMENT => sprintf("%s. Remember, you are loved and you are not alone.", $text),
                AiDjContent::TYPE_TESTIMONY => sprintf("%s. What an amazing testimony.", $text),
                AiDjContent::TYPE_INSPIRATION, AiDjContent::TYPE_STORY => sprintf("And here's something worth sharing. %s.", $text),
                default => sprintf("And here's something for you. %s.", $text),
            };
        }

        return match ($content->type) {
            AiDjContent::TYPE_BIBLE_VERSE => self::cleanScriptureForTts($reference
                ? sprintf("You're listening to %s with %s. I want to share a scripture with you from %s. %s. Let that truth settle in your heart today.", $stationName, $djName, $reference, $text)
                : sprintf("Here's a word from the Lord for you today, from %s on %s. %s. Stay blessed.", $djName, $stationName, $text)),
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
    private function selectStationTemplate(int $stationId, string $type, bool $identFree = false): ?string
    {
        $templates = array_map(
            static fn(AiDjContent $c): string => $c->content,
            $this->contentRepo->findEnabledByType($stationId, $type),
        );
        if ($identFree) {
            $templates = array_values(array_filter($templates, self::isIdentFree(...)));
        }

        if (empty($templates)) {
            return null;
        }

        return $templates[array_rand($templates)];
    }

    /**
     * Get a longer default song intro template for natural-sounding speech.
     */
    private function getDefaultSongIntro(bool $identFree = false): string
    {
        if ($identFree) {
            $defaults = [
                "Here's one for you. {{song}} by {{artist}}. I think you're going to love this.",
                "Coming up, {{artist}} with {{song}}. Let this one speak to your heart.",
                "Next up, it's {{song}} by {{artist}}. Turn it up and enjoy.",
                "I've got something special for you right now. {{artist}}, {{song}}.",
            ];

            return $defaults[array_rand($defaults)];
        }

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
    private function getDefaultPostSong(bool $hasNextSong, bool $identFree = false): string
    {
        if ($identFree) {
            $defaults = [
                "That was {{prev_song}} by {{prev_artist}}. What a beautiful song. More great music is on the way.",
                "{{prev_artist}} with {{prev_song}}. I hope that one blessed you today. Stay with us.",
                "Beautiful music from {{prev_artist}} right there, {{prev_song}}. I'm so glad you're here.",
                "You just heard {{prev_song}} from {{prev_artist}}. We've got plenty more coming your way.",
            ];
            if ($hasNextSong) {
                $defaults[] = "That was {{prev_song}} by {{prev_artist}}. Coming up next, {{next_artist}} with {{next_song}}.";
            }

            return $defaults[array_rand($defaults)];
        }

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
    private function selectRandomTemplate(Collection $contents, bool $identFree = false): ?string
    {
        $templates = $contents
            ->filter(fn(AiDjContent $c) => $c->is_enabled && $c->type === AiDjContent::TYPE_SONG_INTRO_TEMPLATE)
            ->map(fn(AiDjContent $c) => $c->content)
            ->toArray();
        if ($identFree) {
            $templates = array_filter($templates, self::isIdentFree(...));
        }

        if ([] === $templates) {
            return null;
        }

        return $templates[array_rand($templates)];
    }

    /**
     * Select a random post_song_template from the DJ's content collection.
     */
    private function selectRandomPostSongTemplate(
        Collection $contents,
        bool $allowNext = true,
        bool $identFree = false,
    ): ?string {
        $templates = $contents
            ->filter(fn(AiDjContent $c) => $c->is_enabled && $c->type === AiDjContent::TYPE_POST_SONG_TEMPLATE)
            ->map(fn(AiDjContent $c) => $c->content)
            ->toArray();
        if ($identFree) {
            $templates = array_filter($templates, self::isIdentFree(...));
        }

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
     * Put the DJ's uploaded music under a voice clip the way a jock talks over
     * a bed: the music plays alone for the lead-in, drops under the voice just
     * before it starts, comes back up when the talking ends, and fades out.
     * The clip gets longer by the lead-in and the tail. Any failure leaves the
     * dry voice clip, so a break is never lost to the bed.
     *
     * @param array{path: string, lead_in: float, tail: float, from_start: bool, min_voice: float} $bed
     */
    private function mixWithMusicBed(string $voicePath, array $bed): string
    {
        $mixedPath = dirname($voicePath) . '/bed_' . uniqid() . '.mp3';

        try {
            $voice = $this->musicBed->duration($voicePath);
            $bedLength = $this->musicBed->duration($bed['path']);
            if ($voice <= 0.0 || $bedLength <= 0.0 || $voice < $bed['min_voice']) {
                return $voicePath;
            }

            $lead = $bed['lead_in'];
            $total = $lead + $voice + $bed['tail'];
            // Somewhere new in the piece each time, except where the break opens it.
            $room = $bedLength - $total - 1.0;
            $offset = ($bed['from_start'] || $room <= 0.0) ? 0.0 : (float)random_int(0, (int)floor($room));

            // Down over 0.8s as the voice comes in, back up over 1.5s after it.
            $downFrom = max(0.0, $lead - 0.8);
            $upFrom = $lead + $voice;
            $upTo = $upFrom + 1.5;
            $fadeOut = min(3.0, $bed['tail']);
            $gain = sprintf(
                "if(lt(t,%1\$.2f),1,if(lt(t,%2\$.2f),1-(1-%5\$.2f)*(t-%1\$.2f)/%6\$.2f,"
                . "if(lt(t,%3\$.2f),%5\$.2f,if(lt(t,%4\$.2f),%5\$.2f+(1-%5\$.2f)*(t-%3\$.2f)/1.5,1))))",
                $downFrom,
                $lead,
                $upFrom,
                $upTo,
                self::BED_DUCK_GAIN,
                max(0.01, $lead - $downFrom)
            );

            $filterGraph = sprintf(
                '[1:a]atrim=0:%1$.2f,loudnorm=I=%2$.1f:TP=-2:LRA=7,aresample=44100,aformat=channel_layouts=stereo,'
                . "volume='%3\$s':eval=frame,afade=t=in:st=0:d=1,afade=t=out:st=%4\$.2f:d=%5\$.2f[bed];"
                // Mono voice to both sides at full level; the default upmix drops it 3 dB.
                . '[0:a]aresample=44100,aformat=channel_layouts=mono,pan=stereo|c0=c0|c1=c0,adelay=%6$d:all=1[voice];'
                . '[voice][bed]amix=inputs=2:duration=longest:normalize=0,alimiter=limit=0.95:level=disabled[out]',
                $total,
                self::BED_LUFS,
                $gain,
                $total - $fadeOut,
                $fadeOut,
                (int)round($lead * 1000)
            );

            $ffmpeg = new Process([
                self::FFMPEG_BIN, '-y',
                '-i', $voicePath,
                '-stream_loop', '-1', '-ss', sprintf('%.2f', $offset), '-i', $bed['path'],
                '-filter_complex', $filterGraph,
                '-map', '[out]', '-vn',
                '-t', sprintf('%.2f', $total),
                '-ac', '2', '-c:a', 'libmp3lame', '-b:a', '192k',
                $mixedPath,
            ]);
            $ffmpeg->setTimeout(40);
            $ffmpeg->run();

            if (!$ffmpeg->isSuccessful() || !is_file($mixedPath)) {
                $this->logger->warning(sprintf(
                    'AI DJ music bed mix failed; the break airs without it: %s',
                    mb_substr($ffmpeg->getErrorOutput(), -400)
                ));
                @unlink($mixedPath);
                return $voicePath;
            }

            if (!@rename($mixedPath, $voicePath)) {
                @unlink($mixedPath);
            }

            return $voicePath;
        } catch (Throwable $e) {
            $this->logger->warning(sprintf('AI DJ music bed error; the break airs without it: %s', $e->getMessage()));
            @unlink($mixedPath);
            return $voicePath;
        }
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
    private function truncateForTts(string $text, ?int $limit = null): string
    {
        $max = $limit ?? self::MAX_TTS_CHARS;

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $max);
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
