<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Podcast\RssAtomFeedItems;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\Process\Process;
use Throwable;

final class AiNewsGenerator
{
    use LoggerAwareTrait;

    private const string PIPER_BIN = 'piper';
    private const string FFMPEG_BIN = 'ffmpeg';
    private const string PIPER_VOICES_DIR = '/usr/local/share/piper-voices';
    private const string DEFAULT_VOICE_MODEL =
        '/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx';

    /**
     * Fallback Piper voices when the voice library directory is missing
     * (e.g. local non-Docker PHPUnit runs).
     *
     * @var list<array{label: string, path: string}>
     */
    public const array AVAILABLE_VOICE_MODELS = [
        [
            'label' => 'Lessac (Default)',
            'path' => '/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx',
        ],
        [
            'label' => 'Joe',
            'path' => '/usr/local/share/piper-voices/en/en_US/joe/medium/en_US-joe-medium.onnx',
        ],
        [
            'label' => 'Ryan',
            'path' => '/usr/local/share/piper-voices/en/en_US/ryan/medium/en_US-ryan-medium.onnx',
        ],
    ];

    /**
     * Discover every installed Piper .onnx voice under the Docker voice library.
     * Shared by AI Newscaster and AI DJ Piper fallback voice pickers.
     *
     * @return list<array{label: string, path: string}>
     */
    public static function getAvailableVoiceModels(): array
    {
        $voicesDir = self::PIPER_VOICES_DIR;
        if (!is_dir($voicesDir)) {
            return self::AVAILABLE_VOICE_MODELS;
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $voicesDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if (!str_ends_with($name, '.onnx') || str_ends_with($name, '.onnx.json')) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $basename = $file->getBasename('.onnx');
            $found[] = [
                'label' => $basename,
                'path' => $path,
            ];
        }

        if ($found === []) {
            return self::AVAILABLE_VOICE_MODELS;
        }

        usort(
            $found,
            static fn(array $a, array $b): int => strcmp($a['label'], $b['label'])
        );

        return $found;
    }

    public const string OUTPUT_FILENAME = 'news_bulletin.mp3';
    public const array DEFAULT_SOURCE_URLS = [
        'https://worthynews.com/',
        'https://www.raptureready.com/',
    ];
    private const int MAX_STORY_COUNT = 25;
    private const int HTTP_TIMEOUT = 30;

    public function __construct(
        private readonly Client $httpClient,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Generate an AI news bulletin for a given station.
     *
     * Validates config (enabled + active-hours), fetches headlines from the
     * configured RSS/Atom feed sources, builds a deterministic script, runs local
     * Piper TTS, converts WAV→MP3 via ffmpeg, writes atomically to the Liquidsoap
     * path, and persists ai_news_last_generation_status/time/error.
     *
     * @return bool True if generation succeeded or was intentionally skipped.
     */
    public function generate(Station $station, bool $force = false): bool
    {
        $backendConfig = $station->backend_config;

        if (!$force && !$backendConfig->ai_news_enabled) {
            $this->logger->debug(
                sprintf('AI news disabled for station "%s".', $station->name)
            );
            return true;
        }

        if (!$force && !$this->isWithinActiveSchedule(
            $backendConfig->ai_news_active_hours,
            $backendConfig->ai_news_active_days,
            $station
        )) {
            $this->logger->debug(
                sprintf('Outside active AI news schedule for station "%s".', $station->name)
            );
            return true;
        }

        try {
            $maxHeadlines = max(1, min(self::MAX_STORY_COUNT, $backendConfig->ai_news_story_count));
            $startTime = microtime(true);
            $sourceUrls = $this->parseSourceUrls($backendConfig->ai_news_source_urls);
            if ([] === $sourceUrls) {
                $this->persistStatus($station, 'error', 'No source URLs configured.', null);
                throw new RuntimeException('No source URLs configured.');
            }

            $fetchResults = $this->fetchHeadlines($sourceUrls, $maxHeadlines);
            $headlines = $fetchResults['headlines'];
            $sourceResults = $fetchResults['source_results'];
            if ([] === $headlines) {
                $message = 'No website or feed headlines could be fetched from configured sources.';
                $this->persistStatus($station, 'error', $message, [
                    'source_results' => $sourceResults,
                ]);
                throw new RuntimeException($message);
            }

            $intro = $backendConfig->ai_news_intro ?: 'Here are the latest headlines.';
            $script = $this->buildScript(
                $intro,
                $headlines,
                $backendConfig->ai_news_reporter_name,
                $backendConfig->ai_news_outro
            );

            $tempDir = $station->getRadioTempDir();
            $outputPath = $tempDir . '/' . self::OUTPUT_FILENAME;
            $this->generateAudio(
                $script,
                $backendConfig->ai_news_voice_model_path,
                $tempDir,
                $outputPath
            );

            $elapsedSeconds = round(microtime(true) - $startTime, 2);
            $metadata = [
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'story_count' => count($headlines),
                'source_urls' => $sourceUrls,
                'source_results' => $sourceResults,
                'elapsed_seconds' => $elapsedSeconds,
                'output_filename' => self::OUTPUT_FILENAME,
                'headline_preview' => $headlines,
            ];
            $this->persistStatus($station, 'completed', null, $metadata);

            $this->logger->info(
                sprintf('AI news bulletin generated for station "%s".', $station->name)
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('AI news generation failed for station "%s": %s', $station->name, $e->getMessage())
            );

            if ('error' !== $station->ai_news_last_generation_status) {
                try {
                    if (!$this->em->isOpen()) {
                        $this->em->getConnection()->close();
                        $this->em->getConnection()->connect();
                    }
                    $this->persistStatus($station, 'error', $e->getMessage(), null);
                } catch (Throwable) {
                    // EntityManager could not be recovered; status persisted on next successful run.
                }
            }

            throw $e;
        }
    }

    /**
     * Check whether the current station-local time falls within the configured schedule.
     *
     * Hours formats: "HH:MM-HH:MM" (e.g. "06:00-22:00", UI default) or "H-H" (e.g. "6-22", legacy).
     * Days use ISO weekdays 1=Mon .. 7=Sun. Empty days means every day.
     * Supports overnight hour ranges. Null/empty hours means always active.
     */
    private function isWithinActiveSchedule(?string $activeHours, array $activeDays, Station $station): bool
    {
        // Generation runs at :50 for the bulletin Liquidsoap stages at :59, and
        // Liquidsoap checks the window at :59. Check it at that same moment, or
        // the first bulletin of a window like "11:59-17:59" is never generated
        // (11:50 is outside) and a bulletin is made for the hour that ends it.
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $stagedAt = $now->setTime((int)$now->format('G'), 59);
        $activeDays = $this->normalizeActiveDays($activeDays);

        if ([] !== $activeDays && !in_array((int) $stagedAt->format('N'), $activeDays, true)) {
            return false;
        }

        return $this->isWithinActiveHours($activeHours, $stagedAt);
    }

    /**
     * Check whether the current station-local time falls within the configured window.
     *
     * Formats: "HH:MM-HH:MM" (e.g. "06:00-22:00", UI default) or "H-H" (e.g. "6-22", legacy).
     * Supports overnight ranges. Null/empty means always active.
     */
    private function isWithinActiveHours(?string $activeHours, DateTimeImmutable $now): bool
    {
        if (null === $activeHours || '' === trim($activeHours)) {
            return true;
        }

        $activeHours = trim($activeHours);
        $currentHour = (int) $now->format('G');
        $currentMinute = (int) $now->format('i');

        // HH:MM-HH:MM format (preferred, UI default)
        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            $startMinutes = ((int) $matches[1]) * 60 + (int) $matches[2];
            $endMinutes   = ((int) $matches[3]) * 60 + (int) $matches[4];
            $nowMinutes   = $currentHour * 60 + $currentMinute;

            if ($startMinutes <= $endMinutes) {
                return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
            }
            return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $activeHours, $matches)) {
            $start = (int) $matches[1];
            $end   = (int) $matches[2];

            if ($start <= $end) {
                return $currentHour >= $start && $currentHour < $end;
            }
            return $currentHour >= $start || $currentHour < $end;
        }

        return true;
    }

    /** @return int[] */
    private function normalizeActiveDays(array $activeDays): array
    {
        $normalizedDays = array_map(
            static fn(mixed $day): int => (int) $day,
            $activeDays
        );
        $normalizedDays = array_values(array_unique(array_filter(
            $normalizedDays,
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($normalizedDays);

        return $normalizedDays;
    }

    /**
     * @return list<string>
     */
    private function parseSourceUrls(string $sourceUrls): array
    {
        return array_values(
            array_filter(
                array_map('trim', explode("\n", $sourceUrls)),
                static fn (string $url): bool => '' !== $url
            )
        );
    }

    /**
     * @param list<string> $urls
     * @return array{
     *   headlines: list<array{title: string, description: string, source_url: string, source_type?: string}>,
     *   source_results: list<array{url: string, status: string, message: string, headline_count: int, source_type?: string}>
     * }
     */
    private function fetchHeadlines(array $urls, int $maxHeadlines): array
    {
        $headlines = [];
        $sourceResults = [];
        $sourceCount = count($urls);
        $baseHeadlineCount = intdiv($maxHeadlines, $sourceCount);
        $remainderHeadlineCount = $maxHeadlines % $sourceCount;

        foreach ($urls as $index => $url) {
            $sourceHeadlineLimit = $baseHeadlineCount + ($index < $remainderHeadlineCount ? 1 : 0);
            if (0 === $sourceHeadlineLimit) {
                $sourceResults[] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => 'No headline slot allocated for this source.',
                    'headline_count' => 0,
                    'source_type' => 'unknown',
                ];
                continue;
            }

            try {
                $result = $this->fetchAndParseUrl($url, $sourceHeadlineLimit);
                $items = $result['headlines'];
                $headlineCount = count($items);
                $sourceType = $result['source_type'];

                foreach ($items as $item) {
                    $headlines[] = [
                        ...$item,
                        'source_url' => $url,
                        'source_type' => $sourceType,
                    ];
                }

                $sourceResults[] = [
                    'url' => $url,
                    'status' => $headlineCount > 0 ? 'ok' : 'empty',
                    'message' => $headlineCount > 0
                        ? sprintf('Fetched %d headline(s) via %s.', $headlineCount, $sourceType)
                        : sprintf('%s parsing completed but returned no usable headlines.', ucfirst($sourceType)),
                    'headline_count' => $headlineCount,
                    'source_type' => $sourceType,
                ];
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Source "%s" skipped: %s', $url, $e->getMessage())
                );
                $sourceResults[] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => $e->getMessage(),
                    'headline_count' => 0,
                    'source_type' => 'unknown',
                ];
            }
        }

        return [
            'headlines' => array_slice($headlines, 0, $maxHeadlines),
            'source_results' => $sourceResults,
        ];
    }

    /**
     * @return array{
     *   headlines: list<array{title: string, description: string}>,
     *   source_type: string
     * }
     */
    private function fetchAndParseUrl(string $url, int $maxHeadlines): array
    {
        $response = $this->fetchUrl($url);
        $body = (string) $response->getBody();
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        $feedHeadlines = $this->extractFeedHeadlines($body, $maxHeadlines);
        if ([] !== $feedHeadlines) {
            return [
                'headlines' => $feedHeadlines,
                'source_type' => 'feed',
            ];
        }

        if ($this->isLikelyHtmlDocument($body, $contentType)) {
            throw new RuntimeException('This source is not an RSS/Atom feed. Only RSS/Atom feed URLs are supported.');
        }

        throw new RuntimeException('No usable RSS/Atom headlines could be found at this source URL.');
    }

    private function fetchUrl(string $url): ResponseInterface
    {
        return $this->httpClient->get($url, [
            RequestOptions::TIMEOUT => self::HTTP_TIMEOUT,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => true,
            ],
            RequestOptions::HEADERS => [
                'User-Agent' => 'AzuraCast/1.0 (AI News Generator)',
            ],
        ]);
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractFeedHeadlines(string $body, int $maxHeadlines): array
    {
        $xml = @simplexml_load_string($body);
        if (false === $xml) {
            return [];
        }

        $items = RssAtomFeedItems::fromParsedXml($xml);

        $headlines = [];
        foreach ($items as $item) {
            $title = $this->extractTextField($item, 'title');
            if ('' === $title) {
                continue;
            }

            $headlines[] = [
                'title' => $title,
                'description' => $this->extractTextField($item, 'description'),
            ];

            if (count($headlines) >= $maxHeadlines) {
                break;
            }
        }

        return $headlines;
    }

    private function isLikelyHtmlDocument(string $body, string $contentType): bool
    {
        if (str_contains($contentType, 'text/html')) {
            return true;
        }

        if (str_contains($contentType, 'xml') || str_contains($contentType, 'rss') || str_contains($contentType, 'atom')) {
            return false;
        }

        return (bool) preg_match('/<(html|body|article|main)\b/i', $body);
    }

    private function extractTextField(SimpleXMLElement $item, string $field): string
    {
        if (!isset($item->{$field})) {
            return '';
        }

        $text = trim(strip_tags((string) $item->{$field}));
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param list<array{title: string, description: string, source_url?: string}> $headlines
     */
    private function buildScript(
        string $intro,
        array $headlines,
        ?string $reporterName = null,
        ?string $outro = null
    ): string {
        $lines = [];

        $reporterName = null !== $reporterName ? trim($reporterName) : null;
        if (!empty($reporterName)) {
            $lines[] = sprintf('This is %s.', $reporterName);
            $this->appendPauseBreak($lines, 2);
        }

        $lines[] = $intro;
        $this->appendPauseBreak($lines, 2);

        $headlineCount = count($headlines);
        foreach ($headlines as $index => $item) {
            $line = rtrim($item['title'], ".!? ") . '.';

            if ('' !== $item['description']) {
                $line .= ' ' . $this->truncateAtSentenceEnd($item['description']);
            }

            $lines[] = $line;

            if ($index < ($headlineCount - 1)) {
                $this->appendPauseBreak($lines, 1);
            }
        }

        $outro = null !== $outro ? trim($outro) : null;
        if (!empty($outro)) {
            $this->appendPauseBreak($lines, 2);
            $lines[] = $outro;
        }

        return implode("\n", $lines);
    }

    /**
     * Add paragraph separators to encourage short natural pauses in TTS output.
     */
    private function appendPauseBreak(array &$lines, int $extraBlankLines = 1): void
    {
        $blankLines = max(1, $extraBlankLines);
        for ($i = 0; $i < $blankLines; $i++) {
            $lines[] = '';
        }
    }

    private function truncateAtSentenceEnd(string $description): string
    {
        $description = trim($description);
        if ('' === $description) {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $description, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $sentences || [] === $sentences) {
            return $description;
        }

        $softLimit = 420;
        $selected = '';

        foreach ($sentences as $index => $sentence) {
            $candidate = '' === $selected
                ? trim($sentence)
                : $selected . ' ' . trim($sentence);

            if (mb_strlen($candidate) > $softLimit && '' !== $selected) {
                break;
            }

            $selected = $candidate;

            if ($index >= 1 || mb_strlen($selected) >= $softLimit) {
                break;
            }
        }

        return '' !== $selected ? $selected : $description;
    }

    private function generateAudio(
        string $script,
        ?string $voiceModelPath,
        string $tempDir,
        string $outputPath
    ): void {
        $modelPath = $voiceModelPath ?: self::DEFAULT_VOICE_MODEL;

        $scriptFile = $tempDir . '/news_script.txt';
        if (false === file_put_contents($scriptFile, $script)) {
            throw new RuntimeException('Failed to write TTS script file.');
        }

        $wavFile = $tempDir . '/news_bulletin.wav';
        $tmpMp3 = $tempDir . '/news_bulletin_tmp.mp3';

        try {
            $piper = new Process([
                self::PIPER_BIN,
                '--model', $modelPath,
                '--output_file', $wavFile,
            ]);
            $piper->setInput($script);
            $piper->setTimeout(120);
            $piper->mustRun();

            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $wavFile,
                '-af', 'adelay=2000:all=true,apad=pad_dur=2',
                '-c:a', 'libmp3lame',
                '-b:a', '128k',
                $tmpMp3,
            ]);
            $ffmpeg->setTimeout(60);
            $ffmpeg->mustRun();

            if (!@rename($tmpMp3, $outputPath)) {
                throw new RuntimeException(
                    sprintf('Failed to move bulletin to "%s".', $outputPath)
                );
            }
        } finally {
            @unlink($scriptFile);
            @unlink($wavFile);
            @unlink($tmpMp3);
        }
    }

    private function persistStatus(Station $station, string $status, ?string $error, ?array $metadata = null): void
    {
        $station->ai_news_last_generation_status = $status;
        $station->ai_news_last_generation_time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $station->ai_news_last_error = $error;
        if (null !== $metadata) {
            $station->ai_news_latest_bulletin = $this->sanitizeForJson($metadata);
        }

        $this->em->persist($station);
        $this->em->flush();
    }

    /**
     * Recursively sanitize an array so all string values are valid UTF-8,
     * preventing Doctrine JSON serialization failures on scraped content.
     */
    private function sanitizeForJson(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        });
        return $data;
    }
}
