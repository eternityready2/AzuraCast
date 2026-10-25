<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Files;

use App\Cache\MediaListCache;
use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Controller\Api\Traits\HasMediaSearch;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationPlaylistFolderRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\StationMedia;
use App\Entity\StationMediaCategory;
use App\Entity\StationPlaylist;
use App\Exception\CannotProcessMediaException;
use App\Exception\StorageLocationFullException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Media\MediaProcessor;
use App\Media\MetadataLookup;
use App\Message\LookupMediaMetadataMessage;
use App\OpenApi;
use App\Service\Flow;
use App\Utilities\Types;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Messenger\MessageBus;

#[
    OA\Post(
        path: '/station/{station_id}/files/upload',
        operationId: 'postUploadFile',
        summary: 'Upload and process a new media file.',
        requestBody: new OA\RequestBody(
            ref: OpenApi::REF_REQUEST_BODY_FLOW_FILE_UPLOAD
        ),
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class FlowUploadAction implements SingleActionInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;
    use HasMediaSearch;

    public function __construct(
        private readonly MediaProcessor $mediaProcessor,
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationPlaylistFolderRepository $spfRepo,
        private readonly MediaListCache $mediaListCache,
        private readonly MessageBus $messageBus,
        private readonly MetadataLookup $metadataLookup
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $allParams = $request->getParams();
        $station = $request->getStation();

        $mediaStorage = $station->media_storage_location;
        $mediaStorage->errorIfFull();

        $flowResponse = Flow::process($request, $response, $station->getRadioTempDir());
        if ($flowResponse instanceof ResponseInterface) {
            return $flowResponse;
        }

        $currentDir = Types::string($request->getParam('currentDirectory'));

        // Type and category picked for this file in the upload box (optional).
        $mediaType = Types::stringOrNull($request->getParam('media_type'), true);
        if (null !== $mediaType) {
            $allowedTypes = array_map(
                static fn(ClockWheelSlotTypes $case): string => $case->value,
                ClockWheelSlotTypes::cases()
            );
            if (!in_array($mediaType, $allowedTypes, true)) {
                throw new InvalidArgumentException('Invalid media type specified.');
            }
        }

        $category = null;
        $categoryIdRaw = Types::stringOrNull($request->getParam('category_id'), true);
        if (null !== $categoryIdRaw && 'none' !== $categoryIdRaw) {
            if (!is_numeric($categoryIdRaw)) {
                throw new InvalidArgumentException('Invalid category specified.');
            }
            $category = $this->em->find(StationMediaCategory::class, (int)$categoryIdRaw);
            if (!$category instanceof StationMediaCategory || $category->station->id !== $station->id) {
                throw new InvalidArgumentException('Invalid category specified.');
            }
        }

        $destPath = $flowResponse->getClientFullPath();
        if (!empty($currentDir)) {
            $destPath = $currentDir . '/' . $destPath;
        }

        $uploadedSize = $flowResponse->getSize();

        if (!$mediaStorage->canHoldFile($uploadedSize)) {
            throw new StorageLocationFullException();
        }

        try {
            $tempPath = $flowResponse->getUploadedPath();

            $stationMedia = $this->mediaProcessor->processAndUpload(
                $mediaStorage,
                $destPath,
                $tempPath
            );
        } catch (CannotProcessMediaException $e) {
            $this->logger->error(
                $e->getMessageWithPath(),
                [
                    'exception' => $e,
                ]
            );

            return $response->withJson(Error::fromException($e));
        }

        if ($stationMedia instanceof StationMedia) {
            // Set before the lookup runs, so talk/ID/promo files are not looked up as music.
            if (null !== $mediaType || null !== $category) {
                if (null !== $mediaType) {
                    $stationMedia->type = $mediaType;
                }
                if (null !== $category) {
                    $stationMedia->category = $category;
                }
                $this->em->persist($stationMedia);
                $this->em->flush();
            }

            // Year/Label/Writers/ISWC are shared by the review box and Edit Media, so make sure they exist.
            $this->metadataLookup->customFields();

            if ($station->backend_config->media_lookup_on_upload) {
                $lookupMessage = new LookupMediaMetadataMessage();
                $lookupMessage->station_id = $station->id;
                $lookupMessage->media_id = $stationMedia->id;
                $this->messageBus->dispatch($lookupMessage);
            }

            if (!empty($allParams['searchPhrase'])) {
                // If the user is looking at a playlist's contents, add uploaded media to that playlist.
                [, $playlist] = $this->parseSearchQuery(
                    $station,
                    $allParams['searchPhrase']
                );

                if (null !== $playlist) {
                    $this->spmRepo->addMediaToPlaylist($stationMedia, $playlist);
                    $this->em->flush();
                }
            } elseif (!empty($currentDir)) {
                // If the user is viewing a regular directory, check for playlists assigned to the directory and assign
                // them to this media immediately.
                $parentFolders = $this->spfRepo->getPlaylistFoldersForPath($station, $currentDir, true);

                if (!empty($parentFolders)) {
                    foreach ($parentFolders as $folder) {
                        $this->spmRepo->addMediaToPlaylist(
                            media: $stationMedia,
                            playlist: $folder->playlist,
                            folder: $folder
                        );
                    }

                    $this->em->flush();
                }
            }
        }

        $mediaStorage->addStorageUsed($uploadedSize);
        $this->em->persist($mediaStorage);
        $this->em->flush();

        $this->mediaListCache->clearCache($mediaStorage);

        $status = Status::created();

        return $response->withJson([
            'success' => $status->success,
            'message' => $status->message,
            'formatted_message' => $status->formatted_message,
            'media' => ($stationMedia instanceof StationMedia)
                ? [
                    'id' => $stationMedia->id,
                    'unique_id' => $stationMedia->unique_id,
                    'path' => $stationMedia->path,
                ]
                : null,
        ]);
    }
}
