<?php

declare(strict_types=1);

namespace App\Media;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StorageLocationRepository;
use App\Entity\StationMedia;
use App\Entity\StationPlaylistMedia;
use App\Entity\StorageLocation;
use App\Flysystem\ExtendedFilesystemInterface;
use App\Message\JoinMediaMessage;
use App\Utilities\File;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Joins several files of a programme into one new file, in the same folder.
 *
 * The parts are left as they are. Parts that share a format are joined as they
 * are, with no loss of quality; parts in different formats are re-encoded into
 * the format of the first one. The joined file carries the typed name as its
 * title and the first part's artist, album, type, category and cover art.
 */
final class MediaJoiner
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public const string STATUS_RUNNING = 'running';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';

    private const string FFMPEG_BIN = 'ffmpeg';
    private const string FFPROBE_BIN = 'ffprobe';

    /** A re-encode of a three-hour programme runs a few minutes; this is the ceiling, not the norm. */
    private const int JOIN_TIMEOUT_SECONDS = 3600;

    /** For a lossy joined file whose parts are all uncompressed. */
    private const int DEFAULT_BITRATE = 192000;

    /** How long the page can still ask how a join went. */
    private const int STATUS_TTL_SECONDS = 86400;

    public function __construct(
        private readonly StationMediaRepository $mediaRepo,
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
        private readonly StorageLocationRepository $storageLocationRepo,
        private readonly MediaProcessor $mediaProcessor,
        private readonly BatchUtilities $batchUtilities,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Check a join before it is queued, so anything wrong with the request is
     * told to the operator at once instead of failing in the background.
     *
     * @param string[] $paths The parts, in the order they are joined.
     * @return array{path: string, media_ids: int[], reencode: bool, seconds: float}
     */
    public function plan(StorageLocation $storageLocation, array $paths, string $name): array
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidArgumentException('Give the joined file a name.');
        }
        if (count($paths) < 2) {
            throw new InvalidArgumentException('Tick at least two files to join.');
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new InvalidArgumentException('The same file is listed twice.');
        }

        $fs = $this->storageLocationRepo->getAdapter($storageLocation)->getFilesystem();

        $parts = [];
        foreach ($paths as $path) {
            $media = $this->mediaRepo->findByPath($path, $storageLocation);
            if (!($media instanceof StationMedia) || !$fs->fileExists($path)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a media file that can be joined.', $path));
            }
            $parts[] = $media;
        }

        $target = $this->targetPath($parts[0], $name);
        if ($fs->fileExists($target)) {
            throw new InvalidArgumentException(
                sprintf('A file named "%s" is already in this folder.', basename($target))
            );
        }

        return [
            'path' => $target,
            'media_ids' => array_map(static fn(StationMedia $media): int => $media->id, $parts),
            'reencode' => !$this->canCopy($fs, $parts, $target),
            'seconds' => array_sum(array_map(static fn(StationMedia $media): float => $media->length, $parts)),
        ];
    }

    public function __invoke(JoinMediaMessage $message): void
    {
        $this->setStatus($message->job_id, self::STATUS_RUNNING);

        try {
            $storageLocation = $this->em->find(StorageLocation::class, $message->storage_location_id);
            if (!($storageLocation instanceof StorageLocation)) {
                throw new RuntimeException('The media folder of this station was not found.');
            }

            $parts = [];
            foreach ($message->media_ids as $mediaId) {
                $media = $this->em->find(StationMedia::class, $mediaId);
                if (!($media instanceof StationMedia)) {
                    throw new RuntimeException('One of the files was removed before it could be joined.');
                }
                $parts[] = $media;
            }

            $joined = $this->join($storageLocation, $parts, $message->name, $message->replace_in_playlists);

            $this->setStatus($message->job_id, self::STATUS_DONE, path: $joined->path);
        } catch (Throwable $e) {
            $this->logger->error('Joining media files failed.', ['exception' => $e]);
            $this->setStatus($message->job_id, self::STATUS_FAILED, error: $e->getMessage());
        }
    }

    /**
     * @return array{status: string, path: string|null, error: string|null}|null
     */
    public function getStatus(string $jobId): ?array
    {
        /** @var array{status: string, path: string|null, error: string|null}|null $status */
        $status = $this->cache->get(self::statusKey($jobId));

        return is_array($status) ? $status : null;
    }

    public function setStatus(string $jobId, string $status, ?string $path = null, ?string $error = null): void
    {
        $this->cache->set(
            self::statusKey($jobId),
            ['status' => $status, 'path' => $path, 'error' => $error],
            self::STATUS_TTL_SECONDS
        );
    }

    private static function statusKey(string $jobId): string
    {
        return 'media_join.' . preg_replace('/[^a-f0-9]/', '', $jobId);
    }

    /**
     * @param StationMedia[] $parts
     */
    private function join(
        StorageLocation $storageLocation,
        array $parts,
        string $name,
        bool $replaceInPlaylists
    ): StationMedia {
        $fs = $this->storageLocationRepo->getAdapter($storageLocation)->getFilesystem();
        $first = $parts[0];

        $target = $this->targetPath($first, $name);
        if ($fs->fileExists($target)) {
            throw new RuntimeException(
                sprintf('A file named "%s" is already in this folder.', basename($target))
            );
        }

        $output = File::generateTempPath('join.' . Path::getExtension($target));
        $temporary = [$output];

        try {
            $inputs = [];
            foreach ($parts as $part) {
                if ($fs->isLocal()) {
                    $inputs[] = $fs->getLocalPath($part->path);
                    continue;
                }

                $local = File::generateTempPath('join_part.' . Path::getExtension($part->path));
                $temporary[] = $local;
                $fs->download($part->path, $local);
                $inputs[] = $local;
            }

            $tags = [
                '-map_metadata', '-1',
                '-metadata', 'title=' . trim($name),
                '-metadata', 'artist=' . ($first->artist ?? ''),
                '-metadata', 'album=' . ($first->album ?? ''),
            ];

            if ($this->canCopy($fs, $parts, $target)) {
                $list = File::generateTempPath('join_list.txt');
                $temporary[] = $list;
                file_put_contents(
                    $list,
                    implode('', array_map(
                        static fn(string $input): string => "file '" . str_replace("'", "'\\''", $input) . "'\n",
                        $inputs
                    ))
                );

                $command = [
                    self::FFMPEG_BIN, '-y', '-v', 'error',
                    '-f', 'concat', '-safe', '0', '-i', $list,
                    '-map', '0:a:0', '-c', 'copy',
                    ...$tags,
                    $output,
                ];
            } else {
                $streams = array_map(fn(string $input): array => $this->probe($input), $inputs);

                $command = [self::FFMPEG_BIN, '-y', '-v', 'error'];
                $filter = '';
                foreach ($inputs as $i => $input) {
                    array_push($command, '-i', $input);
                    $filter .= sprintf('[%d:a:0]', $i);
                }
                array_push(
                    $command,
                    '-filter_complex',
                    sprintf('%sconcat=n=%d:v=0:a=1[joined]', $filter, count($inputs)),
                    '-map',
                    '[joined]',
                    '-ar',
                    (string)$streams[0]['sample_rate'],
                    '-ac',
                    (string)$streams[0]['channels'],
                );

                // A lossy format is written at the best bitrate among the lossy
                // parts, so none comes out worse than it went in. An uncompressed
                // part (a WAV) has no bitrate worth matching.
                if (!in_array(strtolower(Path::getExtension($target)), ['wav', 'flac'], true)) {
                    $bitrates = array_column(
                        array_filter(
                            $streams,
                            static fn(array $stream): bool => $stream['bit_rate'] > 0
                                && !str_starts_with($stream['codec_name'], 'pcm_')
                                && !in_array($stream['codec_name'], ['flac', 'alac'], true)
                        ),
                        'bit_rate'
                    );
                    array_push(
                        $command,
                        '-b:a',
                        (string)min([] === $bitrates ? self::DEFAULT_BITRATE : max($bitrates), 320000)
                    );
                }

                array_push($command, ...$tags);
                $command[] = $output;
            }

            $process = new Process($command);
            $process->setTimeout(self::JOIN_TIMEOUT_SECONDS);
            $process->run();

            if (!$process->isSuccessful() || !is_file($output) || 0 === filesize($output)) {
                throw new RuntimeException(
                    'The files could not be joined: ' . (trim($process->getErrorOutput()) ?: 'no audio was written.')
                );
            }

            $joined = $this->mediaProcessor->processAndUpload($storageLocation, $target, $output);
            if (!($joined instanceof StationMedia)) {
                throw new RuntimeException('The joined file could not be added to the library.');
            }
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }

        // A file with no artist has its title read back from its file name;
        // the joined file keeps the name as it was typed.
        $joined->title = trim($name);
        $joined->artist = $first->artist;
        $joined->album = $first->album;
        $joined->text = null;
        $joined->updateMetaFields();
        $joined->type = $first->type;
        $joined->category = $first->category;
        $this->em->persist($joined);
        $this->em->flush();

        $artPath = StationMedia::getArtPath($first->unique_id);
        if ($fs->fileExists($artPath)) {
            $this->mediaRepo->updateAlbumArt($joined, $fs->read($artPath));
            $this->em->flush();
        }

        if ($replaceInPlaylists) {
            $this->replaceInPlaylists($parts, $joined);
        }

        return $joined;
    }

    /**
     * Put the joined file where its parts were, in every playlist that holds
     * all of them, and take the parts out of that playlist. A playlist holding
     * only some of the parts is not playing this programme and is left alone.
     *
     * Nothing already queued is pulled: a part that is cued or planned still
     * airs, and the joined file takes over from the playlist's next pick.
     *
     * @param StationMedia[] $parts
     */
    private function replaceInPlaylists(array $parts, StationMedia $joined): void
    {
        /** @var array<int, array<int, StationPlaylistMedia[]>> $rows playlist id => part index => its rows */
        $rows = [];
        foreach ($parts as $i => $part) {
            foreach ($part->playlists as $row) {
                $rows[$row->playlist->id][$i][] = $row;
            }
        }

        $affected = [];
        foreach ($rows as $playlistId => $byPart) {
            if (count($byPart) !== count($parts)) {
                continue;
            }

            $playlistRows = array_merge(...array_values($byPart));
            $playlist = $playlistRows[0]->playlist;
            $weight = $playlistRows[0]->weight;

            foreach ($playlistRows as $row) {
                $weight = min($weight, $row->weight);
                $this->em->remove($row);
            }
            // The removal has to land first: a playlist that is not sequential
            // looks its media up before adding it.
            $this->em->flush();

            $this->playlistMediaRepo->addMediaToPlaylist($joined, $playlist, $weight);
            $affected[$playlistId] = $playlistId;
        }

        $this->em->flush();
        $this->batchUtilities->writePlaylistChanges($affected);
    }

    private function targetPath(StationMedia $first, string $name): string
    {
        $fileName = File::sanitizeFileName(trim($name));
        if ('' === $fileName) {
            throw new InvalidArgumentException('Give the joined file a name with letters or numbers in it.');
        }

        $directory = Path::getDirectory($first->path);
        $fileName .= '.' . strtolower(Path::getExtension($first->path));

        return '' === $directory ? $fileName : $directory . '/' . $fileName;
    }

    /**
     * Parts can be joined as they are when they are all the kind of audio the
     * joined file will be: same file type, codec, sample rate and channels.
     *
     * @param StationMedia[] $parts
     */
    private function canCopy(ExtendedFilesystemInterface $fs, array $parts, string $target): bool
    {
        $extension = strtolower(Path::getExtension($target));

        $formats = [];
        foreach ($parts as $part) {
            if (strtolower(Path::getExtension($part->path)) !== $extension) {
                return false;
            }

            $stream = $fs->withLocalFile($part->path, fn(string $local): array => $this->probe($local));
            $formats[] = [$stream['codec_name'], $stream['sample_rate'], $stream['channels']];
        }

        return 1 === count(array_unique($formats, SORT_REGULAR));
    }

    /**
     * @return array{codec_name: string, sample_rate: int, channels: int, bit_rate: int}
     */
    private function probe(string $localPath): array
    {
        $process = new Process([
            self::FFPROBE_BIN, '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_name,sample_rate,channels,bit_rate',
            '-of', 'json',
            $localPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        $stream = json_decode($process->getOutput(), true)['streams'][0] ?? null;
        if (!$process->isSuccessful() || !is_array($stream)) {
            throw new RuntimeException(sprintf('"%s" has no audio that can be joined.', basename($localPath)));
        }

        return [
            'codec_name' => (string)($stream['codec_name'] ?? ''),
            'sample_rate' => (int)($stream['sample_rate'] ?? 44100),
            'channels' => (int)($stream['channels'] ?? 2),
            'bit_rate' => (int)($stream['bit_rate'] ?? 0),
        ];
    }
}
