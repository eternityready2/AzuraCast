<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Features;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Api\Status;
use App\Entity\Listener;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Exception\Supervisor\AlreadyRunningException;
use App\Exception\SupervisorException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Media\MediaProcessor;
use App\Media\MetadataLookup;
use App\Message\BuildLinearLogMessage;
use App\Radio\AbstractLocalAdapter;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap\ConfigWriter as LiquidsoapConfigWriter;
use App\Radio\Configuration;
use App\Radio\Frontend\Icecast;
use App\Radio\AutoDJ\LinearLog\LinearLogAlerts;
use App\Radio\AutoDJ\LinearLog\LinearLogHandEdits;
use App\Radio\AutoDJ\LinearLog\LinearLogStore;
use App\Radio\AutoDJ\LinearLog\LinearLogTiming;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Service\AirCheckFrontendConnectivityProbe;
use App\Service\GuzzleFactory;
use App\Sync\Task\BuildLinearLogTask;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

final class FeatureSuiteController
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly Adapters $adapters,
        private readonly GuzzleFactory $guzzleFactory,
        private readonly MediaProcessor $mediaProcessor,
        private readonly LinearLogSnapshotStore $linearLogSnapshotStore,
        private readonly LinearLogStore $linearLogStore,
        private readonly LinearLogTiming $linearLogTiming,
        private readonly LinearLogHandEdits $linearLogHandEdits,
        private readonly LinearLogAlerts $linearLogAlerts,
        private readonly MessageBus $messageBus,
        private readonly Configuration $configuration,
        private readonly CacheInterface $cache,
        private readonly AirCheckFrontendConnectivityProbe $frontendConnectivityProbe,
        private readonly MetadataLookup $metadataLookup,
    ) {
    }

    public function getAirCheckAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $config = $request->getStation()->backend_config;

        return $response->withJson([
            'enabled' => $config->aircheck_enabled,
            'interval_minutes' => $config->aircheck_interval_minutes,
            'last_check' => $config->aircheck_last_check,
            'interventions' => array_values($config->aircheck_interventions),
        ]);
    }

    public function saveAirCheckAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $data = (array)$request->getParsedBody();
        $config = $station->backend_config;
        $config->aircheck_enabled = (bool)($data['enabled'] ?? false);
        $config->aircheck_interval_minutes = max(1, min(60, (int)($data['interval_minutes'] ?? 10)));
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }

    public function runAirCheckAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $result = $this->runAirCheck($station, true);
        return $response->withJson($result);
    }

    /**
     * Detect on-disk configs that no longer match the database credentials and rewrite them.
     *
     * Symptom this fixes: Liquidsoap keeps running (so the normal running check passes) but every
     * request is rejected with "Invalid API key" and Icecast answers 401, because liquidsoap.liq or
     * icecast.xml was generated from older credentials than the database now holds.
     */
    private function repairCredentialDrift(Station $station): bool
    {
        // A pending manual restart means the person changed something on purpose; leave it alone.
        if ($station->needs_restart || !$station->is_enabled) {
            return false;
        }

        $apiKey = (string)$station->adapter_api_key;
        $sourcePw = $station->frontend_config->source_pw;
        if ('' === $apiKey) {
            return false;
        }

        $drift = [];

        $backend = $this->adapters->getBackendAdapter($station);
        if (null !== $backend && $backend->hasCommand($station)) {
            $path = $backend->getConfigurationPath($station);
            $contents = is_file($path) ? @file_get_contents($path) : false;

            if (is_string($contents) && '' !== $contents) {
                if (!str_contains($contents, LiquidsoapConfigWriter::toRawString($apiKey))) {
                    $drift[] = 'liquidsoap.liq API key';
                }

                if (
                    1 === preg_match('/^[A-Za-z0-9]+$/', $sourcePw)
                    && !str_contains($contents, LiquidsoapConfigWriter::toRawString($sourcePw))
                ) {
                    $drift[] = 'liquidsoap.liq source password';
                }
            }
        }

        $frontend = $this->adapters->getFrontendAdapter($station);
        if ($frontend instanceof Icecast && $frontend->hasCommand($station)) {
            $path = $frontend->getConfigurationPath($station);
            $contents = is_file($path) ? @file_get_contents($path) : false;

            if (
                is_string($contents)
                && '' !== $contents
                && 1 === preg_match('/^[A-Za-z0-9]+$/', $sourcePw)
                && !str_contains($contents, $sourcePw)
            ) {
                $drift[] = 'icecast.xml source password';
            }
        }

        if ([] === $drift) {
            return false;
        }

        // Never loop: at most one automatic rewrite per station every 15 minutes.
        $cacheKey = 'aircheck_credential_repair_' . $station->id;
        if (null !== $this->cache->get($cacheKey)) {
            return false;
        }
        $this->cache->set($cacheKey, time(), 900);

        $this->configuration->writeConfiguration(
            station: $station,
            forceRestart: true,
            attemptReload: false
        );

        return true;
    }

    private function waitForRunning(object $adapter, Station $station, int $seconds): bool
    {
        if (!($adapter instanceof AbstractLocalAdapter)) {
            return false;
        }

        for ($i = 0; $i < $seconds; $i++) {
            sleep(1);

            try {
                if ($adapter->isRunning($station)) {
                    return true;
                }
            } catch (Throwable) {
                // Keep waiting; the final answer is whether it is running after the window.
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function runAirCheck(Station $station, bool $manual = false): array
    {
        $needsRestartBefore = $station->needs_restart;
        $config = $station->backend_config;
        $now = time();
        if (!$manual && (!$config->aircheck_enabled
            || ($now - $config->aircheck_last_check) < ($config->aircheck_interval_minutes * 60))) {
            return ['checked' => false, 'restarted' => []];
        }

        $restarted = [];
        $failures = [];

        $repairedDrift = false;
        try {
            $repairedDrift = $this->repairCredentialDrift($station);
        } catch (Throwable $e) {
            $failures[] = 'config: ' . $e->getMessage();
        }

        if ($repairedDrift) {
            $restarted = ['backend', 'frontend'];
        }

        foreach ($repairedDrift ? [] : ['backend', 'frontend'] as $service) {
            try {
                $adapter = 'backend' === $service
                    ? $this->adapters->getBackendAdapter($station)
                    : $this->adapters->getFrontendAdapter($station);

                if (null === $adapter) {
                    continue;
                }

                $isRunning = $adapter->isRunning($station);

                if ($isRunning) {
                    if (
                        'frontend' === $service
                        && !$this->frontendConnectivityProbe->isReachable($station, $adapter)
                    ) {
                        // Supervisor sees the process as RUNNING -- it never crashed --
                        // but it is not actually accepting listener connections (e.g. a
                        // full/stuck TCP accept queue on Icecast). There is nothing for
                        // Supervisor to "come back" from here, so skip the
                        // starting/BACKOFF grace period below and restart directly.
                        try {
                            $adapter->restart($station);
                        } catch (AlreadyRunningException|SupervisorException $e) {
                            if (!$this->waitForRunning($adapter, $station, 6)) {
                                throw $e;
                            }
                        }

                        $restarted[] = $service;
                    }

                    continue;
                }

                // Supervisor reports "not running" while a process is STARTING or auto-restarting
                // (BACKOFF). Give it a moment before fighting it, otherwise our start() races
                // Supervisor's own restart and fails with "already running" or
                // AbnormalTerminationException even though the service comes back.
                if ($this->waitForRunning($adapter, $station, 4)) {
                    continue;
                }

                try {
                    $adapter->restart($station);
                } catch (AlreadyRunningException $e) {
                    // Supervisor started it between our check and our start.
                    if (!$this->waitForRunning($adapter, $station, 6)) {
                        throw $e;
                    }
                } catch (SupervisorException $e) {
                    // Our start can lose the race against Supervisor's own restart
                    // (port or lock still held), so only report failure if the
                    // service is still down after it settles.
                    if (!$this->waitForRunning($adapter, $station, 6)) {
                        throw $e;
                    }
                }

                $restarted[] = $service;
            } catch (Throwable $e) {
                $failures[] = $service . ': ' . $e->getMessage();
            }
        }

        if ([] !== $restarted || [] !== $failures) {
            $history = $config->aircheck_interventions;
            array_unshift($history, [
                'timestamp' => $now,
                'services' => $restarted,
                'failures' => $failures,
                'manual' => $manual,
            ]);
            $config->aircheck_interventions = array_slice($history, 0, 20);
        }

        $config->aircheck_last_check = $now;
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return [
            'checked' => true,
            'healthy' => [] === $restarted && [] === $failures,
            'restarted' => $restarted,
            'failures' => $failures,
            'timestamp' => $now,
        ];
    }

    public function listShowsAction(ServerRequest $request, Response $response): ResponseInterface
    {
        return $response->withJson(array_values($request->getStation()->backend_config->feature_shows));
    }

    public function saveShowAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $data = (array)$request->getParsedBody();
        $config = $station->backend_config;
        $shows = array_values($config->feature_shows);
        $id = trim((string)($data['id'] ?? ''));
        if ('' === $id) {
            $id = bin2hex(random_bytes(8));
        }

        $show = [
            'id' => $id,
            'name' => trim((string)($data['name'] ?? 'Untitled Show')),
            'description' => trim((string)($data['description'] ?? '')),
            'enabled' => (bool)($data['enabled'] ?? true),
            'color' => (string)($data['color'] ?? '#667eea'),
            'priority' => (string)($data['priority'] ?? 'programme'),
            'allow_overrun' => (bool)($data['allow_overrun'] ?? false),
            'segments' => array_values((array)($data['segments'] ?? [])),
            'schedules' => array_values((array)($data['schedules'] ?? [])),
            'updated_at' => time(),
        ];

        $found = false;
        foreach ($shows as $i => $existing) {
            if (($existing['id'] ?? null) === $id) {
                $shows[$i] = $show;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $shows[] = $show;
        }


        $config->feature_shows = $shows;
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson($show);
    }

    public function deleteShowAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $id = (string)$params['id'];
        $config = $station->backend_config;
        $config->feature_shows = array_values(array_filter(
            $config->feature_shows,
            static fn(array $show): bool => (string)($show['id'] ?? '') !== $id
        ));
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::deleted());
    }

    public function linearLogStatusAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $snapshot = $this->linearLogSnapshotStore->get($station);
        $hours = $station->backend_config->linear_log_hours;
        $coverage = null;
        $alerts = [];
        $gapsAhead = [];

        // Live times, like an FM automation log: re-timed from what is actually
        // playing on every load instead of waiting for the next rebuild.
        if ($station->backend_config->linear_log_enabled && !empty($snapshot['entries'])) {
            try {
                $snapshot['entries'] = $this->linearLogStore->liveEntries(
                    $station,
                    $snapshot['entries'],
                    $hours,
                );

                // How deep the log actually is, measured on exactly the rendered
                // lines below -- not a sum of their durations, which stays
                // plausible even with a hole in the middle of the day, and not
                // the raw build-time snapshot, which does not see what the page
                // just filtered out. This is the number an operator can trust.
                $coverage = $this->linearLogStore->measureCoverage($station, $snapshot['entries'], $hours);

                // FM-style hard/soft timing for the HARD marker on the page.
                $snapshot['entries'] = $this->linearLogTiming->tagEntries($station, $snapshot['entries']);

                // Each line's hand edits (what it was before, and whether Undo
                // can still put it back), and what the operator must be told:
                // a hole, a short hour, songs the AutoDJ had to pick itself.
                $snapshot['entries'] = $this->linearLogHandEdits->tagEntries($station, $snapshot['entries']);
                $alerts = $this->linearLogAlerts->forStation($station, $coverage);
                $gapsAhead = $this->linearLogAlerts->gapsAhead($station, $coverage);
            } catch (Throwable $e) {
                // Fall back to the snapshot's own times; no coverage claim.
            }
        }

        return $response->withJson([
            ...$snapshot,
            'enabled' => $station->backend_config->linear_log_enabled,
            // An enabled log always drives playout (no separate switch).
            'playout_enabled' => $station->backend_config->linear_log_enabled,
            'configured_hours' => $hours,
            'next_build_at' => $station->backend_config->linear_log_enabled
                ? BuildLinearLogTask::nextDailyBuildAt($station)
                : null,
            'ai_dj_projection' => 'shifts_only',
            'coverage' => null === $coverage ? null : [
                'continuous_seconds' => $coverage->continuousSeconds(),
                'required_seconds' => $hours * 3600,
                'satisfied' => $coverage->satisfies($hours * 3600),
                'holes' => array_values($coverage->holes),
            ],
            'alerts' => $alerts,
            'gaps_ahead' => $gapsAhead,
        ]);
    }

    public function buildLinearLogAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();

        if (!$station->supportsAutoDjQueue()) {
            throw new InvalidArgumentException('This station does not support the AutoDJ queue.');
        }

        if (!$station->backend_config->linear_log_enabled) {
            throw new InvalidArgumentException('The 24-Hour Playout Log is disabled for this station.');
        }

        $data = (array)$request->getParsedBody();
        $hours = max(1, min(48, (int)($data['hours'] ?? $station->backend_config->linear_log_hours)));

        $this->linearLogSnapshotStore->markQueued($station, $hours);

        try {
            $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours, false, true));
        } catch (Throwable $e) {
            $this->linearLogSnapshotStore->markFailed($station, $hours, $e->getMessage());
            throw $e;
        }

        return $response->withJson([
            'success' => true,
            'status' => 'queued',
            'hours' => $hours,
        ]);
    }

    public function simulateAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $params = $request->getParams();
        $tz = $station->getTimezoneObject();
        $date = (string)($params['date'] ?? CarbonImmutable::now($tz)->format('Y-m-d'));
        $time = (string)($params['time'] ?? CarbonImmutable::now($tz)->format('H:i'));
        $duration = max(5, min(1440, (int)($params['duration'] ?? 60)));
        $start = CarbonImmutable::parse($date . ' ' . $time, $tz);
        $end = $start->addMinutes($duration);

        $scheduleRows = $this->em->createQuery(
            'SELECT s, p FROM App\\Entity\\StationSchedule s JOIN s.playlist p WHERE p.station = :station AND p.is_enabled = true'
        )->setParameter('station', $station)->getResult();

        $windows = [];
        foreach ($scheduleRows as $schedule) {
            if (!$schedule instanceof StationSchedule || !$schedule->playlist instanceof StationPlaylist) {
                continue;
            }
            $window = $this->scheduleWindow($schedule, $start);
            if (null === $window) {
                continue;
            }
            [$windowStart, $windowEnd] = $window;
            if ($windowEnd <= $start || $windowStart >= $end) {
                continue;
            }
            $windows[] = [
                'start' => $windowStart->format('H:i:s'),
                'end' => $windowEnd->format('H:i:s'),
                'name' => $schedule->playlist->name,
                'type' => $schedule->strict_start || $schedule->is_emergency ? 'priority' : 'playlist',
                'priority' => $schedule->is_emergency ? 100 : ($schedule->strict_start ? 80 : 50),
            ];
        }

        foreach ($station->backend_config->feature_shows as $show) {
            if (!($show['enabled'] ?? true)) {
                continue;
            }
            foreach ((array)($show['schedules'] ?? []) as $showSchedule) {
                $scheduleStartDate = trim((string)($showSchedule['start_date'] ?? ''));
                $scheduleEndDate = trim((string)($showSchedule['end_date'] ?? ''));
                if ('' !== $scheduleStartDate && $date < $scheduleStartDate) {
                    continue;
                }
                if ('' !== $scheduleEndDate && $date > $scheduleEndDate) {
                    continue;
                }

                $days = array_map('intval', (array)($showSchedule['days'] ?? []));
                if ([] !== $days && !in_array((int)$start->isoWeekday(), $days, true)) {
                    continue;
                }

                if (($showSchedule['loop_once'] ?? false)
                    && '' !== $scheduleStartDate
                    && $date !== $scheduleStartDate) {
                    continue;
                }

                $st = (string)($showSchedule['start_time'] ?? '00:00');
                $et = (string)($showSchedule['end_time'] ?? $st);
                $ws = CarbonImmutable::parse($date . ' ' . $st, $tz);
                $we = CarbonImmutable::parse($date . ' ' . $et, $tz);
                if ($we <= $ws) {
                    $we = $we->addDay();
                }
                if ($we > $start && $ws < $end) {
                    $priority = (string)($show['priority'] ?? 'programme');
                    $windows[] = [
                        'start' => $ws->format('H:i:s'),
                        'end' => $we->format('H:i:s'),
                        'name' => (string)($show['name'] ?? 'Show'),
                        'type' => 'show',
                        'priority' => 'priority' === $priority ? 120 : 90,
                    ];
                }
            }
        }

        usort(
            $windows,
            static fn(array $a, array $b): int => [$a['start'], -$a['priority']]
                <=> [$b['start'], -$b['priority']]
        );

        $resolved = [];
        if ([] === $windows) {
            $resolved[] = [
                'start' => $start->format('H:i:s'),
                'end' => $end->format('H:i:s'),
                'name' => 'General Rotation',
                'type' => 'rotation',
            ];
        } else {
            foreach ($windows as $window) {
                $resolved[] = $window;
            }
        }

        return $response->withJson([
            'timezone' => $tz->getName(),
            'schedule_windows' => $windows,
            'resolved_timeline' => $resolved,
            'rotation_gaps' => [] === $windows ? $resolved : [],
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function scheduleWindow(StationSchedule $schedule, CarbonImmutable $day): ?array
    {
        $date = $day->format('Y-m-d');
        if ($schedule->start_date && $date < $schedule->start_date) {
            return null;
        }
        if ($schedule->end_date && $date > $schedule->end_date) {
            return null;
        }

        $days = $schedule->days;
        if ([] !== $days && !in_array((int)$day->isoWeekday(), $days, true)) {
            return null;
        }

        $startText = StationSchedule::displayTimeCode($schedule->start_time);
        $endText = StationSchedule::displayTimeCode($schedule->end_time);
        $start = CarbonImmutable::parse($date . ' ' . $startText, $day->getTimezone());
        $end = CarbonImmutable::parse($date . ' ' . $endText, $day->getTimezone());
        if ($end <= $start) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    public function downloadFromUrlAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $data = (array)$request->getParsedBody();
        $url = trim((string)($data['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }

        $parts = parse_url($url);
        $filename = trim((string)($data['filename'] ?? ''));
        if ('' === $filename) {
            $filename = basename((string)($parts['path'] ?? 'download.mp3')) ?: 'download.mp3';
        }
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'download.mp3';

        $directory = trim((string)($data['directory'] ?? ''), '/');
        $destination = '' !== $directory ? $directory . '/' . $filename : $filename;

        $tmp = tempnam($station->getRadioTempDir(), 'url_');
        if (false === $tmp) {
            throw new InvalidArgumentException('Unable to create temporary download file.');
        }

        try {
            $client = $this->guzzleFactory->buildClient();
            $client->request('GET', $url, [
                RequestOptions::SINK => $tmp,
                RequestOptions::TIMEOUT => 120,
                RequestOptions::CONNECT_TIMEOUT => 15,
                RequestOptions::ALLOW_REDIRECTS => ['max' => 5],
                RequestOptions::HTTP_ERRORS => true,
                'headers' => ['User-Agent' => 'AzuraCast URL Importer'],
            ]);

            $size = filesize($tmp) ?: 0;
            if ($size <= 0 || $size > 1024 * 1024 * 1024) {
                throw new InvalidArgumentException('Downloaded file is empty or exceeds the 1 GB limit.');
            }

            $media = $this->mediaProcessor->processAndUpload(
                $station->media_storage_location,
                $destination,
                $tmp
            );
            $tmp = null;

            return $response->withJson([
                'success' => true,
                'path' => $destination,
                'media_id' => $media?->id,
            ]);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function ppcaReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $rows = $this->historyRows($station, $start, $end);
        $csv = [['Date', 'Time', 'Artist', 'Title', 'Record Label', 'ISRC', 'Unique Listeners']];

        foreach ($rows as $row) {
            $csv[] = [
                $row->timestamp_start->setTimezone($station->getTimezoneObject())->format('Y-m-d'),
                $row->timestamp_start->setTimezone($station->getTimezoneObject())->format('H:i:s'),
                $row->artist ?? '', $row->title ?? '',
                ($row->media?->extra_metadata->toArray()['publisher'] ?? ''),
                $row->media?->isrc ?? '',
                (string)($row->unique_listeners ?? 0),
            ];
        }
        return $response->renderStringAsFile($this->csv($csv), 'text/csv', 'ppca-report.csv');
    }

    public function pplReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $rows = $this->historyRows($station, $start, $end);
        $seconds = 0.0;
        foreach ($rows as $row) {
            $seconds += (float)($row->duration ?? 0);
        }

        $periodHours = max(1.0, ($end->getTimestamp() - $start->getTimestamp()) / 3600);
        $tracksPerHour = count($rows) / $periodHours;

        $listenerRows = $this->em->createQuery(
            'SELECT l FROM App\\Entity\\Listener l WHERE l.station = :station AND l.timestamp_start <= :end AND (l.timestamp_end IS NULL OR l.timestamp_end >= :start)'
        )->setParameter('station', $station)->setParameter('start', $start)->setParameter('end', $end)->getResult();
        $listenerSeconds = 0;
        foreach ($listenerRows as $listener) {
            if (!$listener instanceof Listener) {
                continue;
            }
            $ls = max($start->getTimestamp(), $listener->timestamp_start->getTimestamp());
            $le = min($end->getTimestamp(), ($listener->timestamp_end ?? $end)->getTimestamp());
            $listenerSeconds += max(0, $le - $ls);
        }
        $csv = [
            ['Metric', 'Value'],
            ['Average music tracks webcast per hour', number_format($tracksPerHour, 2, '.', '')],
            ['Total listener hours', number_format($listenerSeconds / 3600, 2, '.', '')],
            ['Total performances', number_format($tracksPerHour * ($listenerSeconds / 3600), 2, '.', '')],
            ['Tracked audio seconds', number_format($seconds, 0, '.', '')],
        ];
        return $response->renderStringAsFile($this->csv($csv), 'text/csv', 'ppl-webcasting-report.csv');
    }

    public function cadenceReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $listeners = $this->em->createQuery(
            'SELECT l FROM App\\Entity\\Listener l WHERE l.station = :station AND l.timestamp_start <= :end AND (l.timestamp_end IS NULL OR l.timestamp_end >= :start) ORDER BY l.timestamp_start ASC'
        )->setParameter('station', $station)->setParameter('start', $start)->setParameter('end', $end)->getResult();
        $csv = [['IP address', 'Date', 'Time', 'Stream', 'Duration', 'Status', 'Referrer']];
        foreach ($listeners as $listener) {
            if (!$listener instanceof Listener) {
                continue;
            }
            $s = $listener->timestamp_start->setTimezone($station->getTimezoneObject());
            $e = ($listener->timestamp_end ?? $end)->setTimezone($station->getTimezoneObject());
            $csv[] = [
                $listener->listener_ip,
                $s->format('Y-m-d'),
                $s->format('H:i:s'),
                $listener->mount?->name
                    ?? $listener->remote?->display_name
                    ?? $listener->hls_stream?->name
                    ?? 'default',
                (string)max(0, $e->getTimestamp() - $s->getTimestamp()),
                '200',
                $listener->listener_user_agent,
            ];
        }
        $format = strtolower((string)($request->getParam('format') ?? 'csv'));
        $delimiter = 'txt' === $format ? "\t" : ',';
        $contentType = 'txt' === $format ? 'text/plain' : 'text/csv';
        $extension = 'txt' === $format ? 'txt' : 'csv';

        return $response->renderStringAsFile(
            $this->csv($csv, $delimiter),
            $contentType,
            'cadence-report.' . $extension
        );
    }

    /**
     * ASCAP's Music Use Report for a non-interactive (radio-style) service, in
     * the layout of its published Form 1 for audio-only services: one line per
     * song per day, tab or pipe delimited, with the field lengths the form gives.
     */
    public function ascapReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $tz = $station->getTimezoneObject();
        $saved = $this->metadataLookup->savedDetails($station->media_storage_location);
        $countPlays = 'plays' === $request->getParam('count');

        /** @var array<string, array{play: array<string, mixed>, date: string, count: int}> $songDays */
        $songDays = [];
        foreach ($this->musicPlays($station, $start, $end) as $play) {
            $date = CarbonImmutable::instance($play['timestamp_start'])->setTimezone($tz)->format('mdY');
            $key = $play['media_id'] . '|' . $date;

            $songDays[$key] ??= ['play' => $play, 'date' => $date, 'count' => 0];
            $songDays[$key]['count'] += $countPlays ? 1 : (int)($play['unique_listeners'] ?? 0);
        }

        $delimiter = 'pipe' === $request->getParam('format') ? '|' : "\t";
        $field = static fn(mixed $value, int $length): string => mb_substr(
            trim((string)preg_replace('/[\t\r\n|]+/', ' ', (string)($value ?? ''))),
            0,
            $length
        );

        $lines = [];
        if ('0' !== (string)($request->getParam('header') ?? '1')) {
            $lines[] = implode($delimiter, [
                'Service Name',
                'Service URL',
                'Song Title',
                'Product Name',
                'Writer/Composer Name',
                'Artist/Performer Name',
                'Song Identifier',
                'ISRC',
                'ISWC',
                'Usage Type',
                'Performance Type',
                'Performance Date',
                'Performance Duration',
                'Number of Performances',
            ]);
        }

        foreach ($songDays as $songDay) {
            // A song nobody was listening to was not performed to anyone.
            if (0 === $songDay['count']) {
                continue;
            }

            $play = $songDay['play'];
            $details = $saved[(int)$play['media_id']] ?? [];
            $seconds = (int)round((float)($play['length'] ?? 0));

            $lines[] = implode($delimiter, [
                $field($station->name, 90),
                $field($station->url, 90),
                $field($play['title'], 90),
                // Product Name is for commercials only.
                '',
                $field($details[MetadataLookup::FIELD_WRITERS] ?? '', 90),
                $field($play['artist'], 90),
                $field($play['unique_id'], 250),
                $field(preg_replace('/[^A-Za-z0-9]/', '', (string)($play['isrc'] ?? '')), 12),
                $field(preg_replace('/[^A-Za-z0-9]/', '', (string)($details[MetadataLookup::FIELD_ISWC] ?? '')), 11),
                // F is a feature performance, NI a non-interactive one.
                'F',
                'NI',
                $songDay['date'],
                sprintf('%02d%02d', min(99, intdiv($seconds, 60)), $seconds % 60),
                (string)min($songDay['count'], 99999999),
            ]);
        }

        return $response->renderStringAsFile(
            implode("\r\n", $lines) . "\r\n",
            'text/plain',
            'ascap-music-use-report.txt'
        );
    }

    /**
     * BMI's Music Use Report for a web service, with the details its licence
     * names for each work (title, writer, artist, label, identifiers, length,
     * type of use, times transmitted), and the streaming hours its yearly
     * report asks for.
     */
    public function bmiReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $tz = $station->getTimezoneObject();
        $plays = $this->musicPlays($station, $start, $end);

        if ('hours' === $request->getParam('schedule')) {
            return $response->renderStringAsFile(
                $this->csv([
                    ['Metric', 'Value'],
                    ['Service name', $station->name],
                    ['Period start', $start->setTimezone($tz)->format('Y-m-d')],
                    ['Period end', $end->setTimezone($tz)->format('Y-m-d')],
                    [
                        'Total streaming hours (listener hours)',
                        number_format($this->listenerSeconds($station, $start, $end) / 3600, 2, '.', ''),
                    ],
                    ['Songs played', (string)count($plays)],
                    [
                        'Listener performances',
                        (string)array_sum(array_map(
                            static fn(array $play): int => (int)($play['unique_listeners'] ?? 0),
                            $plays
                        )),
                    ],
                ]),
                'text/csv',
                'bmi-streaming-hours.csv'
            );
        }

        $saved = $this->metadataLookup->savedDetails($station->media_storage_location);

        /** @var array<int, array{play: array<string, mixed>, plays: int, listeners: int}> $songs */
        $songs = [];
        foreach ($plays as $play) {
            $id = (int)$play['media_id'];

            $songs[$id] ??= ['play' => $play, 'plays' => 0, 'listeners' => 0];
            $songs[$id]['plays']++;
            $songs[$id]['listeners'] += (int)($play['unique_listeners'] ?? 0);
        }

        $csv = [[
            'Title',
            'Composer/Writer',
            'Artist',
            'Record Label',
            'ISRC',
            'ISWC',
            'Length',
            'Type of Use',
            'Streamed or Downloaded',
            'Times Transmitted',
            'Listener Performances',
        ]];

        foreach ($songs as $id => $song) {
            $play = $song['play'];
            $details = $saved[$id] ?? [];
            $seconds = (int)round((float)($play['length'] ?? 0));

            $csv[] = [
                (string)($play['title'] ?? ''),
                (string)($details[MetadataLookup::FIELD_WRITERS] ?? ''),
                (string)($play['artist'] ?? ''),
                (string)($details[MetadataLookup::FIELD_LABEL] ?? ''),
                (string)($play['isrc'] ?? ''),
                (string)($details[MetadataLookup::FIELD_ISWC] ?? ''),
                sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60),
                'Feature',
                'Streamed',
                (string)$song['plays'],
                (string)$song['listeners'],
            ];
        }

        $tabbed = 'txt' === strtolower((string)($request->getParam('format') ?? 'csv'));

        return $response->renderStringAsFile(
            $this->csv($csv, $tabbed ? "\t" : ','),
            $tabbed ? 'text/plain' : 'text/csv',
            'bmi-music-use-report.' . ($tabbed ? 'txt' : 'csv')
        );
    }

    /**
     * Every play of a file typed Music in a period, oldest first. Songwriter
     * royalties are owed on songs: shows, station IDs, promos and AI DJ breaks
     * are not listed.
     *
     * @return list<array<string, mixed>>
     */
    private function musicPlays(Station $station, CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var list<array<string, mixed>> $plays */
        $plays = $this->em->createQuery(
            <<<'DQL'
                SELECT m.id AS media_id, m.unique_id, m.title, m.artist, m.isrc, m.length,
                    h.timestamp_start, h.unique_listeners
                FROM App\Entity\SongHistory h
                JOIN h.media m
                WHERE h.station = :station
                AND h.timestamp_start BETWEEN :start AND :end
                AND h.is_visible = true
                AND m.type = 'music'
                ORDER BY h.timestamp_start ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getArrayResult();

        return $plays;
    }

    /** Seconds of listening inside a period, summed over every listener session. */
    private function listenerSeconds(Station $station, CarbonImmutable $start, CarbonImmutable $end): int
    {
        /** @var list<array{timestamp_start: DateTimeInterface, timestamp_end: DateTimeInterface|null}> $sessions */
        $sessions = $this->em->createQuery(
            <<<'DQL'
                SELECT l.timestamp_start, l.timestamp_end
                FROM App\Entity\Listener l
                WHERE l.station = :station
                AND l.timestamp_start <= :end
                AND (l.timestamp_end IS NULL OR l.timestamp_end >= :start)
            DQL
        )->setParameter('station', $station)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getArrayResult();

        $seconds = 0;
        foreach ($sessions as $session) {
            $from = max($start->getTimestamp(), $session['timestamp_start']->getTimestamp());
            $until = min($end->getTimestamp(), ($session['timestamp_end'] ?? $end)->getTimestamp());
            $seconds += max(0, $until - $from);
        }

        return $seconds;
    }

    /** @return array{0: CarbonImmutable,1: CarbonImmutable} */
    private function getReportDates(ServerRequest $request): array
    {
        $tz = $request->getStation()->getTimezoneObject();
        $startDate = (string)($request->getParam('start_date') ?? 'first day of this month');
        $endDate = (string)($request->getParam('end_date') ?? 'today');

        $start = CarbonImmutable::parse($startDate, $tz)
            ->startOfDay()
            ->utc();
        $end = CarbonImmutable::parse($endDate, $tz)
            ->endOfDay()
            ->utc();
        return [$start, $end];
    }

    /** @return SongHistory[] */
    private function historyRows(Station $station, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->em->createQuery(
            'SELECT h, m FROM App\\Entity\\SongHistory h LEFT JOIN h.media m WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND h.is_visible = true ORDER BY h.timestamp_start ASC'
        )->setParameter('station', $station)->setParameter('start', $start)->setParameter('end', $end)->getResult();
    }

    /** @param array<int,array<int,string>> $rows */
    private function csv(array $rows, string $delimiter = ','): string
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, $delimiter, '"', '\\');
        }
        rewind($stream);
        $out = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $out;
    }
}
