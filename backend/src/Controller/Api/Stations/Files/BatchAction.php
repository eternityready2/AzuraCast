<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Files;

use App\Cache\MediaListCache;
use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\MediaBatchResult;
use App\Entity\Interfaces\PathAwareInterface;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Repository\StationPlaylistFolderRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\StationMedia;
use App\Entity\StationMediaCategory;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistFolder;
use App\Entity\StationQueue;
use App\Entity\StationRequest;
use App\Entity\StorageLocation;
use App\Enums\StationPermissions;
use App\Event\Radio\AnnotateNextSong;
use App\Exception\Http\PermissionDeniedException;
use App\Flysystem\ExtendedFilesystemInterface;
use App\Flysystem\StationFilesystems;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Media\BatchUtilities;
use App\Media\GenrePlaylistService;
use App\Media\MediaJoiner;
use App\Media\MetadataLookup;
use App\Message;
use App\OpenApi;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\BackendAdapters;
use App\Radio\Enums\LiquidsoapQueues;
use App\Utilities\File;
use App\Utilities\Time;
use App\Utilities\Types;
use Carbon\CarbonImmutable;
use Exception;
use InvalidArgumentException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use OpenApi\Attributes as OA;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

#[
    OA\Put(
        path: '/station/{station_id}/files/batch',
        operationId: 'putStationFileBatchAction',
        summary: 'Perform a batch action on a collection of files/directories.',
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            // TODO: API Response Body
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class BatchAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly BatchUtilities $batchUtilities,
        private readonly MessageBus $messageBus,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly StationMediaRepository $mediaRepo,
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
        private readonly StationPlaylistFolderRepository $playlistFolderRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly StationFilesystems $stationFilesystems,
        private readonly MediaListCache $mediaListCache,
        private readonly GenrePlaylistService $genrePlaylistService,
        private readonly MediaJoiner $mediaJoiner,
        private readonly MetadataLookup $metadataLookup
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $storageLocation = $station->media_storage_location;

        $fsMedia = $this->stationFilesystems->getMediaFilesystem($station);

        $result = match (Types::string($request->getParam('do'))) {
            'delete' => $this->doDelete($request, $station, $storageLocation, $fsMedia),
            'playlist' => $this->doPlaylist($request, $station, $storageLocation, $fsMedia),
            'move' => $this->doMove($request, $station, $storageLocation, $fsMedia),
            'queue' => $this->doQueue($request, $station, $storageLocation, $fsMedia),
            'immediate' => $this->doPlayImmediately($request, $station, $storageLocation, $fsMedia),
            'reprocess' => $this->doReprocess($request, $station, $storageLocation, $fsMedia),
            'clear-extra' => $this->doClearExtra($request, $station, $storageLocation, $fsMedia),
            'classify' => $this->doClassify($request, $station, $storageLocation, $fsMedia),
            'genre-playlists-preview' => $this->doGenrePlaylistsPreview(
                $request,
                $station,
                $storageLocation,
                $fsMedia
            ),
            'genre-playlists' => $this->doGenrePlaylists($request, $station, $storageLocation, $fsMedia),
            'join' => $this->doJoin($request, $station, $storageLocation, $fsMedia),
            'join-status' => $this->doJoinStatus($request, $station, $storageLocation, $fsMedia),
            'lookup-list' => $this->doLookupList($request, $station, $storageLocation, $fsMedia),
            'lookup' => $this->doLookup($request, $station, $storageLocation, $fsMedia),
            'lookup-apply' => $this->doLookupApply($request, $station, $storageLocation, $fsMedia),
            'lookup-settings' => $this->doLookupSettings($request, $station, $storageLocation, $fsMedia),
            default => throw new InvalidArgumentException('Invalid batch action specified.')
        };

        if ($this->em->isOpen()) {
            $this->em->clear();
        }

        return $response->withJson($result);
    }

    private function doDelete(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        if (!$request->getAcl()->isAllowed(StationPermissions::DeleteMedia, $station)) {
            throw PermissionDeniedException::create($request);
        }

        $result = $this->parseRequest($request, $fs, true);

        $successfulFiles = [];
        foreach ($result->files as $file) {
            try {
                $fs->delete($file);
                $successfulFiles[] = $file;
            } catch (UnableToDeleteFile $e) {
                $result->errors[] = sprintf('%s: %s', $file, $e->reason());
            } catch (Throwable $e) {
                $result->errors[] = sprintf('%s: %s', $file, $e->getMessage());
            }
        }

        $successfulDirs = [];
        foreach ($result->directories as $dir) {
            try {
                $fs->deleteDirectory($dir);
                $successfulDirs[] = $dir;
            } catch (UnableToDeleteDirectory $e) {
                $result->errors[] = sprintf('%s: %s', $dir, $e->reason());
            } catch (Throwable $e) {
                $result->errors[] = sprintf('%s: %s', $dir, $e->getMessage());
            }
        }

        $this->batchUtilities->handleDelete(
            $successfulFiles,
            $successfulDirs,
            $storageLocation,
            $fs
        );

        return $result;
    }

    private function doPlaylist(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, false);

        /** @var array<int, int> $playlists */
        $playlists = [];

        /** @var array<int, int> $affectedPlaylistIds */
        $affectedPlaylistIds = [];

        /** @var string[] $requestPlaylists */
        $requestPlaylists = Types::array($request->getParam('playlists'));

        foreach ($requestPlaylists as $playlistId) {
            if ('new' === $playlistId) {
                $playlist = new StationPlaylist($station);
                $playlist->name = Types::string($request->getParam('new_playlist_name'));

                $this->em->persist($playlist);
                $this->em->flush();

                $result->responseRecord = [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                ];

                $affectedPlaylistIds[$playlist->id] = $playlist->id;
                $playlists[$playlist->id] = 0;
            } else {
                $playlist = $this->em->getRepository(StationPlaylist::class)->findOneBy(
                    [
                        'station' => $station,
                        'id' => (int)$playlistId,
                    ]
                );

                if ($playlist instanceof StationPlaylist) {
                    $affectedPlaylistIds[$playlist->id] = $playlist->id;
                    $playlists[$playlist->id] = $this->playlistMediaRepo->getHighestSongWeight($playlist);
                }
            }
        }

        /*
         * NOTE: This iteration clears the entity manager.
         */
        foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
            try {
                $affectedPlaylistIds += $this->playlistMediaRepo->setPlaylistsForMedia(
                    $media,
                    $station,
                    $playlists
                );
            } catch (Exception $e) {
                $result->errors[] = $media->path . ': ' . $e->getMessage();
            }
        }

        /** @var Station $station */
        $station = $this->em->refetch($station);

        foreach ($result->directories as $dir) {
            try {
                $this->playlistFolderRepo->setPlaylistsForFolder(
                    $station,
                    $dir,
                    $playlists
                );
            } catch (Exception $e) {
                $result->errors[] = $dir . ': ' . $e->getMessage();
            }
        }

        $this->em->flush();

        $this->batchUtilities->writePlaylistChanges($affectedPlaylistIds);

        $this->mediaListCache->clearCache($storageLocation);

        return $result;
    }

    private function doMove(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs);

        $from = Types::string($request->getParam('currentDirectory'));
        $to = Types::string($request->getParam('directory'));

        $affectedPlaylists = [];

        $toMove = [
            $this->batchUtilities->iterateMedia($storageLocation, $result->files),
            $this->batchUtilities->iterateUnprocessableMedia($storageLocation, $result->files),
        ];

        foreach ($toMove as $iterator) {
            foreach ($iterator as $record) {
                /** @var PathAwareInterface $record */
                $oldPath = $record->path;
                $newPath = File::renameDirectoryInPath($oldPath, $from, $to, false);

                try {
                    $fs->move($oldPath, $newPath);

                    $record->path = $newPath;
                    $this->em->persist($record);

                    if ($record instanceof StationMedia) {
                        $affectedPlaylists += $this->playlistMediaRepo->getPlaylistsForMedia($record);
                    }
                } catch (Throwable $e) {
                    $result->errors[] = sprintf('%s: %s', $oldPath, $e->getMessage());
                }
            }
        }

        foreach ($result->directories as $dirPath) {
            $newDirPath = File::renameDirectoryInPath($dirPath, $from, $to);

            try {
                $fs->move($dirPath, $newDirPath);
            } catch (Throwable $e) {
                $result->errors[] = sprintf('%s: %s', $dirPath, $e->getMessage());
                continue;
            }

            $toMove = [
                $this->batchUtilities->iterateMediaInDirectory($storageLocation, $dirPath),
                $this->batchUtilities->iterateUnprocessableMediaInDirectory($storageLocation, $dirPath),
                $this->batchUtilities->iteratePlaylistFoldersInDirectory($storageLocation, $dirPath),
            ];

            foreach ($toMove as $iterator) {
                foreach ($iterator as $record) {
                    /** @var PathAwareInterface $record */
                    try {
                        $record->path = File::renameDirectoryInPath($record->path, $from, $to);
                        $this->em->persist($record);

                        if ($record instanceof StationMedia) {
                            $affectedPlaylists += $this->playlistMediaRepo->getPlaylistsForMedia($record);
                        } else {
                            if ($record instanceof StationPlaylistFolder) {
                                $playlist = $record->playlist;
                                $affectedPlaylists[$playlist->id] = $playlist->id;
                            }
                        }
                    } catch (Throwable $e) {
                        $result->errors[] = $record->path . ': ' . $e->getMessage();
                    }
                }
            }
        }

        $this->batchUtilities->writePlaylistChanges($affectedPlaylists);

        $this->mediaListCache->clearCache($storageLocation);

        return $result;
    }

    private function doQueue(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);

        if ($station->backend_config->use_manual_autodj) {
            foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
                /** @var Station $stationRef */
                $stationRef = $this->em->getReference(Station::class, $station->id);

                $newRequest = new StationRequest($stationRef, $media, null, true);
                $this->em->persist($newRequest);
            }
        } else {
            $nextCuedItem = $this->queueRepo->getNextToSendToAutoDj($station);
            $cuedTimestamp = (null !== $nextCuedItem)
                ? CarbonImmutable::instance($nextCuedItem->timestamp_cued)->subSeconds(10)
                : Time::nowUtc();

            foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
                try {
                    /** @var Station $stationRef */
                    $stationRef = $this->em->getReference(Station::class, $station->id);

                    $newQueue = StationQueue::fromMedia($stationRef, $media);
                    $newQueue->timestamp_cued = $cuedTimestamp;
                    $this->em->persist($newQueue);
                } catch (Throwable $e) {
                    $result->errors[] = sprintf('%s: %s', $media->path, $e->getMessage());
                }

                $cuedTimestamp = $cuedTimestamp->subSeconds(10);
            }
        }

        return $result;
    }

    private function doPlayImmediately(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);

        if (BackendAdapters::Liquidsoap !== $station->backend_type) {
            throw new RuntimeException('This functionality can only be used on stations that use Liquidsoap.');
        }

        /** @var Liquidsoap $backend */
        $backend = $this->adapters->getBackendAdapter($station);

        if ($station->backend_config->use_manual_autodj) {
            foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
                /** @var Station $station */
                $station = $this->em->find(Station::class, $station->id);

                $event = AnnotateNextSong::fromStationMedia($station, $media, true);
                $this->eventDispatcher->dispatch($event);

                $backend->enqueue(
                    $station,
                    LiquidsoapQueues::Interrupting,
                    $event->buildAnnotations()
                );
            }
        } else {
            $cuedTimestamp = Time::nowUtc();

            foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
                try {
                    /** @var Station $station */
                    $station = $this->em->find(Station::class, $station->id);

                    $newQueue = StationQueue::fromMedia($station, $media);
                    $newQueue->timestamp_cued = $cuedTimestamp;
                    $newQueue->is_played = true;

                    $this->em->persist($newQueue);
                    $this->em->flush();

                    $event = AnnotateNextSong::fromStationQueue($newQueue, true);
                    $this->eventDispatcher->dispatch($event);

                    $backend->enqueue(
                        $station,
                        LiquidsoapQueues::Interrupting,
                        $event->buildAnnotations()
                    );
                } catch (Throwable $e) {
                    $result->errors[] = sprintf('%s: %s', $media->path, $e->getMessage());
                }

                $cuedTimestamp = $cuedTimestamp->addSeconds(10);
            }
        }

        return $result;
    }

    private function doReprocess(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);

        foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
            $mediaId = (int)$media->id;

            $message = new Message\ReprocessMediaMessage();
            $message->storage_location_id = $storageLocation->id;
            $message->media_id = $mediaId;
            $message->force = true;

            $this->messageBus->dispatch($message);
        }

        foreach ($this->batchUtilities->iterateUnprocessableMedia($storageLocation, $result->files) as $unprocessable) {
            $message = new Message\AddNewMediaMessage();
            $message->storage_location_id = $storageLocation->id;
            $message->path = $unprocessable->path;

            $this->messageBus->dispatch($message);
        }

        return $result;
    }

    private function doClearExtra(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);

        foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
            $media->extra_metadata = null;

            // Always flag for reprocessing to repopulate extra metadata from the file.
            $media->mtime = 0;

            $this->em->persist($media);
        }

        return $result;
    }

    private function doClassify(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);

        $allowedTypes = array_map(
            static fn (ClockWheelSlotTypes $case): string => $case->value,
            ClockWheelSlotTypes::cases()
        );

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $mediaType = Types::stringOrNull($body['media_type'] ?? null, true);
        $updateType = null !== $mediaType && '' !== $mediaType;

        if ($updateType && !in_array($mediaType, $allowedTypes, true)) {
            throw new InvalidArgumentException('Invalid media type specified.');
        }

        $updateGenre = array_key_exists('genre', $body);
        $genre = null;

        if ($updateGenre) {
            $genre = Types::stringOrNull($body['genre'] ?? null, true);
        }

        $updateCategory = array_key_exists('category_id', $body);
        $category = null;
        if ($updateCategory) {
            $categoryIdRaw = $body['category_id'];

            if (null !== $categoryIdRaw && '' !== $categoryIdRaw && 'none' !== $categoryIdRaw) {
                if (!is_numeric($categoryIdRaw)) {
                    throw new InvalidArgumentException('Invalid category specified.');
                }

                $category = $this->em->find(StationMediaCategory::class, (int)$categoryIdRaw);
                if (!$category instanceof StationMediaCategory || $category->station->id !== $station->id) {
                    throw new InvalidArgumentException('Invalid category specified.');
                }
            }
        }

        if (!$updateType && !$updateCategory && !$updateGenre) {
            throw new InvalidArgumentException('Specify a type, category and/or genre to update.');
        }

        foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
            $oldType = $media->type;
            $oldCategory = $media->category;
            $oldGenre = $media->genre;

            try {
                if ($updateType) {
                    $media->type = $mediaType;
                }

                if ($updateCategory) {
                    $media->category = $category;
                }

                if ($updateGenre) {
                    $media->genre = $genre;

                    if (!$this->mediaRepo->writeToFile($media, $fs)) {
                        throw new RuntimeException('Could not write media metadata to file.');
                    }
                }

                $this->em->persist($media);
            } catch (Throwable $e) {
                $media->type = $oldType;
                $media->category = $oldCategory;
                $media->genre = $oldGenre;

                $result->errors[] = sprintf(
                    '%s: %s',
                    $media->path,
                    $e->getMessage()
                );
            }
        }

        $this->em->flush();
        $this->mediaListCache->clearCache($storageLocation);

        return $result;
    }

    private function doGenrePlaylistsPreview(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);
        $result->responseRecord = $this->genrePlaylistService->preview(
            $station,
            $this->batchUtilities->iterateMedia($storageLocation, $result->files)
        );

        return $result;
    }

    private function doGenrePlaylists(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);
        $result->responseRecord = $this->genrePlaylistService->execute(
            $station,
            $this->batchUtilities->iterateMedia($storageLocation, $result->files)
        );

        $this->mediaListCache->clearCache($storageLocation);

        return $result;
    }

    /**
     * Join the ticked files into one new file in the same folder, in the order
     * given. The join itself runs in the background; what comes back is the
     * job to ask about with join-status.
     */
    private function doJoin(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs);

        $name = Types::string($request->getParam('name'));

        try {
            $plan = $this->mediaJoiner->plan($storageLocation, $result->files, $name);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $result->errors[] = $e->getMessage();
            return $result;
        }

        $message = new Message\JoinMediaMessage();
        $message->job_id = bin2hex(random_bytes(8));
        $message->storage_location_id = $storageLocation->id;
        $message->media_ids = $plan['media_ids'];
        $message->name = $name;
        $message->replace_in_playlists = Types::bool($request->getParam('replace_in_playlists'), false, true);

        $this->mediaJoiner->setStatus($message->job_id, MediaJoiner::STATUS_RUNNING);
        $this->messageBus->dispatch($message);

        $result->responseRecord = [
            'job' => $message->job_id,
            'path' => $plan['path'],
            'reencode' => $plan['reencode'],
            'seconds' => $plan['seconds'],
        ];

        return $result;
    }

    private function doJoinStatus(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = new MediaBatchResult();
        $result->responseRecord = $this->mediaJoiner->getStatus(Types::string($request->getParam('job')))
            ?? ['status' => MediaJoiner::STATUS_FAILED, 'path' => null, 'error' => 'This join is no longer known.'];

        return $result;
    }

    /**
     * The tracks among the ticked files and folders that can be looked up
     * online: music with a title and an artist. Shows, IDs and promos are not
     * in the catalogues, and a lookup would only find the wrong thing.
     */
    private function doLookupList(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs, true);

        // Year and label are saved to these; the page shows them from its next load.
        $this->metadataLookup->customFields();

        $tracks = [];
        $skipped = 0;
        foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
            if (
                'music' !== $media->type
                || '' === trim($media->title ?? '')
                || '' === trim($media->artist ?? '')
            ) {
                $skipped++;
                continue;
            }

            $tracks[] = [
                'path' => $media->path,
                'title' => $media->title,
                'artist' => $media->artist,
            ];
        }

        $result->responseRecord = [
            'tracks' => $tracks,
            'skipped' => $skipped,
            ...$this->lookupSettings($station),
        ];

        return $result;
    }

    /** Look one track up. Nothing is saved; the result is for review. */
    private function doLookup(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = $this->parseRequest($request, $fs);

        foreach ($this->batchUtilities->iterateMedia($storageLocation, array_slice($result->files, 0, 1)) as $media) {
            try {
                $result->responseRecord = $this->metadataLookup->lookup($station, $media);
            } catch (Throwable $e) {
                $result->errors[] = $e->getMessage();
            }
        }

        return $result;
    }

    /** Save the details accepted in the review, per track. */
    private function doLookupApply(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $result = new MediaBatchResult();

        /** @var array<string, string[]> $accepted path => details */
        $accepted = [];
        foreach (Types::array($request->getParam('accepted')) as $row) {
            $accepted[Types::string($row['path'] ?? null)] = array_map(
                static fn(mixed $field): string => Types::string($field),
                Types::array($row['fields'] ?? null)
            );
        }

        $result->files = array_keys($accepted);

        $saved = [];
        foreach ($this->batchUtilities->iterateMedia($storageLocation, $result->files) as $media) {
            try {
                $saved[$media->path] = $this->metadataLookup->apply($media, $accepted[$media->path] ?? []);
            } catch (Throwable $e) {
                $result->errors[] = sprintf('%s: %s', $media->path, $e->getMessage());
                continue;
            }

            // The picture comes from another site at the moment it is saved.
            if (
                in_array(MetadataLookup::FIELD_ART, $accepted[$media->path] ?? [], true)
                && !in_array(MetadataLookup::FIELD_ART, $saved[$media->path], true)
            ) {
                $result->errors[] = sprintf(
                    '%s: the cover art could not be downloaded; the other details were saved.',
                    $media->path
                );
            }
        }

        $this->mediaListCache->clearCache($storageLocation);

        $result->responseRecord = ['saved' => $saved];

        return $result;
    }

    /** Read the lookup settings, or save the ones sent. */
    private function doLookupSettings(
        ServerRequest $request,
        Station $station,
        StorageLocation $storageLocation,
        ExtendedFilesystemInterface $fs
    ): MediaBatchResult {
        $config = $station->backend_config;
        $changed = false;

        $token = $request->getParam('discogs_token');
        if (null !== $token) {
            $config->media_lookup_discogs_token = trim(Types::string($token));
            $changed = true;
        }

        $onUpload = $request->getParam('on_upload');
        if (null !== $onUpload) {
            $config->media_lookup_on_upload = Types::bool($onUpload, false, true);
            $changed = true;
        }

        if ($changed) {
            // A lookup setting never calls for the station to be restarted.
            $needsRestartBefore = $station->needs_restart;
            $station->backend_config = $config;
            $station->needs_restart = $needsRestartBefore;
            $this->em->persist($station);
            $this->em->flush();
        }

        $result = new MediaBatchResult();
        $result->responseRecord = $this->lookupSettings($station);

        return $result;
    }

    /**
     * @return array{has_discogs_token: bool, has_lastfm_key: bool, on_upload: bool}
     */
    private function lookupSettings(Station $station): array
    {
        return [
            'has_discogs_token' => '' !== trim($station->backend_config->media_lookup_discogs_token ?? ''),
            'has_lastfm_key' => $this->metadataLookup->hasLastFmKey(),
            'on_upload' => $station->backend_config->media_lookup_on_upload,
        ];
    }

    private function parseRequest(
        ServerRequest $request,
        ExtendedFilesystemInterface $fs,
        bool $recursive = false
    ): MediaBatchResult {
        $files = array_values(
            Types::array($request->getParam('files', []))
        );
        $directories = array_values(
            Types::array($request->getParam('dirs', []))
        );

        if ($recursive) {
            foreach ($directories as $dir) {
                $dirIterator = $fs->listContents($dir, true)->filter(
                    function (StorageAttributes $attrs) {
                        return $attrs->isFile();
                    }
                );

                foreach ($dirIterator as $subDirMeta) {
                    $files[] = $subDirMeta['path'];
                }
            }
        }

        $result = new MediaBatchResult();
        $result->files = $files;
        $result->directories = $directories;

        return $result;
    }
}
