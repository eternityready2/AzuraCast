<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\AutoDJ\LinearLog\LinearLogStore;
use App\Radio\AutoDJ\LinearLog\LinearLogTiming;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use Carbon\CarbonImmutable;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * The saved linear log as a file: CSV for a spreadsheet, or a plain page that
 * prints cleanly. Same lines and times as the Linear Log page.
 */
final class LinearLogExportAction
{
    public function __construct(
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogStore $logStore,
        private readonly LinearLogTiming $timing,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $tz = $station->getTimezoneObject();
        $hours = $station->backend_config->linear_log_hours;

        $snapshot = $this->snapshotStore->get($station);
        $entries = $snapshot['entries'] ?? [];
        if ($station->backend_config->linear_log_enabled && !empty($entries)) {
            try {
                $entries = $this->logStore->liveEntries($station, $entries, $hours);
            } catch (Throwable) {
                // Fall back to the snapshot's own times.
            }
        }
        $entries = $this->timing->tagEntries($station, $entries);

        $header = ['Date', 'Time', 'Timing', 'Length', 'Type', 'Title', 'Artist', 'Playlist', 'Status', 'Locked', 'Note'];
        $rows = [];
        foreach ($entries as $entry) {
            $start = CarbonImmutable::createFromTimestamp((int)($entry['played_at'] ?? 0), $tz);
            $length = (int)round((float)($entry['duration'] ?? 0));

            $rows[] = [
                $start->format('Y-m-d'),
                $start->format('H:i:s'),
                'hard' === ($entry['timing'] ?? null) ? 'H' : 'S',
                sprintf('%d:%02d', intdiv($length, 60), $length % 60),
                (string)($entry['media_type'] ?? ''),
                (string)($entry['title'] ?? $entry['text'] ?? ''),
                (string)($entry['artist'] ?? ''),
                (string)($entry['playlist'] ?? ''),
                (string)($entry['log_status'] ?? 'planned'),
                !empty($entry['is_locked']) ? 'yes' : '',
                (string)($entry['log_note'] ?? ''),
            ];
        }

        $name = $station->short_name . '_linear_log_' . CarbonImmutable::now($tz)->format('Ymd_His');

        if ('print' === $request->getParam('format')) {
            return $response->renderStringAsFile(
                $this->renderPrintable($station->name, $header, $rows),
                'text/html; charset=utf-8',
            );
        }

        $csv = fopen('php://temp', 'r+');
        if (false === $csv) {
            throw new RuntimeException('Could not open a buffer for the CSV export.');
        }
        fputcsv($csv, $header, ',', '"', '');
        foreach ($rows as $row) {
            // A leading = + - @ would run as a formula when the file is opened.
            fputcsv(
                $csv,
                array_map(
                    static fn(string $cell): string => preg_match('/^[=+\-@]/', $cell) ? "'" . $cell : $cell,
                    $row
                ),
                ',',
                '"',
                ''
            );
        }
        rewind($csv);
        $body = (string)stream_get_contents($csv);
        fclose($csv);

        return $response->renderStringAsFile($body, 'text/csv; charset=utf-8', $name . '.csv');
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    private function renderPrintable(string $stationName, array $header, array $rows): string
    {
        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $esc($stationName)
            . ' - Linear Log</title><style>'
            . 'body{font:12px/1.35 Arial,Helvetica,sans-serif;margin:16px;color:#000}'
            . 'h1{font-size:16px;margin:0 0 8px}'
            . 'table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #999;padding:3px 6px;text-align:left;vertical-align:top}'
            . 'th{background:#eee}tr{page-break-inside:avoid}'
            . '</style></head><body><h1>' . $esc($stationName) . ' - Linear Log</h1><table><thead><tr>';

        foreach ($header as $cell) {
            $html .= '<th>' . $esc($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $esc($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></body></html>';
    }
}
