<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationMedia;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

/**
 * Hand edits on the saved linear log ("live assist"): lock, move, remove or
 * replace a planned line. Only lines that have not been queued yet can change;
 * the log is re-timed by a background build afterwards.
 */
final class LinearLogEntryAction
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly LinearLogSnapshotStore $snapshotStore,
    ) {
    }

    /** @param array<string, string> $params */
    public function editAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        if (!LinearLogPlayout::isPlayoutEnabled($station)) {
            throw new InvalidArgumentException('Hand edits need "Log Controls Playout" turned on.');
        }

        $entry = $this->em->find(StationLogEntry::class, (int)($params['entry_id'] ?? 0));
        if (!$entry instanceof StationLogEntry || $entry->station->id !== $station->id) {
            throw new InvalidArgumentException('Log line not found.');
        }
        if (StationLogEntry::STATUS_PLANNED !== $entry->status) {
            throw new InvalidArgumentException('This line is already queued or has aired and can no longer be changed.');
        }

        $data = (array)$request->getParsedBody();

        switch ($params['edit'] ?? '') {
            case 'lock':
                $entry->is_locked = true;
                break;

            case 'unlock':
                $entry->is_locked = false;
                break;

            case 'up':
            case 'down':
                $this->move($station, $entry, 'up' === $params['edit']);
                break;

            case 'remove':
                $this->em->remove($entry);
                break;

            case 'replace':
                $media = $this->em->find(StationMedia::class, (int)($data['media_id'] ?? 0));
                if (
                    !$media instanceof StationMedia
                    || $media->storage_location->id !== $station->media_storage_location->id
                ) {
                    throw new InvalidArgumentException('Pick a file from this station\'s library.');
                }

                $planned = $entry->text;
                $entry->media = $media;
                $entry->title = $media->title;
                $entry->artist = $media->artist;
                $entry->text = mb_substr((string)$media->text, 0, 255);
                $entry->duration = max(1.0, $media->getCalculatedLength());
                // Old clock wheel caps belonged to the old song.
                $entry->payload = [
                    'song_id' => $media->song_id,
                    'album' => $media->album,
                    'media_type' => $media->type,
                ];
                $entry->note = mb_substr('Replaced by hand; planned: ' . ($planned ?? 'unknown'), 0, 255);
                // A hand-picked song must not be re-planned by a rebuild.
                $entry->is_locked = true;
                break;

            default:
                throw new InvalidArgumentException('Unknown edit.');
        }

        // Changes are only saved for entities passed to persist().
        if ('remove' !== $params['edit']) {
            $this->em->persist($entry);
        }
        $this->em->flush();
        $this->refresh($station);

        return $response->withJson(['success' => true]);
    }

    /**
     * Library search for the Replace picker.
     */
    public function mediaAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $query = trim((string)$request->getParam('q', ''));
        if (mb_strlen($query) < 2) {
            return $response->withJson([]);
        }

        /** @var StationMedia[] $media */
        $media = $this->em->createQuery(
            <<<'DQL'
                SELECT sm FROM App\Entity\StationMedia sm
                WHERE sm.storage_location = :storageLocation
                AND (sm.title LIKE :q OR sm.artist LIKE :q OR sm.text LIKE :q)
                ORDER BY sm.artist ASC, sm.title ASC
            DQL
        )->setParameter('storageLocation', $station->media_storage_location)
            ->setParameter('q', '%' . addcslashes($query, '%_') . '%')
            ->setMaxResults(25)
            ->getResult();

        return $response->withJson(array_map(
            static fn(StationMedia $sm): array => [
                'id' => $sm->id,
                'title' => $sm->title,
                'artist' => $sm->artist,
                'text' => $sm->text,
                'length' => $sm->getCalculatedLength(),
                'type' => $sm->type,
            ],
            $media
        ));
    }

    /** Swap play order with the neighbouring planned line. */
    private function move(Station $station, StationLogEntry $entry, bool $up): void
    {
        $neighbor = $this->em->createQuery(
            $up
                ? 'SELECT e FROM App\Entity\StationLogEntry e WHERE e.station = :station
                    AND e.status = :planned AND e.sequence < :seq ORDER BY e.sequence DESC'
                : 'SELECT e FROM App\Entity\StationLogEntry e WHERE e.station = :station
                    AND e.status = :planned AND e.sequence > :seq ORDER BY e.sequence ASC'
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('seq', $entry->sequence)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (!$neighbor instanceof StationLogEntry) {
            throw new InvalidArgumentException(
                $up ? 'This is already the next line to play.' : 'This is the last line in the log.'
            );
        }

        [$entry->sequence, $neighbor->sequence] = [$neighbor->sequence, $entry->sequence];
        [$entry->planned_at, $neighbor->planned_at] = [$neighbor->planned_at, $entry->planned_at];
        // A moved line stays where the operator put it.
        $entry->is_locked = true;
        $this->em->persist($neighbor);
    }

    /** Re-time the log in the background (extend only, never re-plan). */
    private function refresh(Station $station): void
    {
        $hours = $station->backend_config->linear_log_hours;
        $this->snapshotStore->markQueued($station, $hours);
        try {
            $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours, true, false));
        } catch (Throwable $e) {
            $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
        }
    }
}
