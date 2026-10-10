<?php

declare(strict_types=1);

namespace App\Media;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\CustomField;
use App\Entity\Repository\CustomFieldRepository;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationMediaCustomField;
use App\Entity\StorageLocation;
use App\Message\LookupMediaMetadataMessage;
use App\Service\Discogs;
use App\Service\LastFm;
use App\Service\MusicBrainz;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

/**
 * Looks a track up online, as FM music libraries do, building on the lookups
 * AzuraCast already has: MusicBrainz first, then Discogs, then Last.fm. The
 * first source that knows a detail supplies it.
 *
 * Nothing is saved by a lookup. What was found is kept for an hour so the
 * operator can review it against what the track has now and accept or skip
 * each detail; only apply() changes the track.
 */
final class MetadataLookup
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public const string FIELD_ALBUM = 'album';
    public const string FIELD_GENRE = 'genre';
    public const string FIELD_YEAR = 'year';
    public const string FIELD_LABEL = 'label';
    public const string FIELD_ISRC = 'isrc';
    public const string FIELD_WRITERS = 'writers';
    public const string FIELD_ISWC = 'iswc';
    public const string FIELD_ART = 'art';

    public const array FIELDS = [
        self::FIELD_ALBUM,
        self::FIELD_GENRE,
        self::FIELD_YEAR,
        self::FIELD_LABEL,
        self::FIELD_ISRC,
        self::FIELD_WRITERS,
        self::FIELD_ISWC,
        self::FIELD_ART,
    ];

    public const string SOURCE_MUSICBRAINZ = 'MusicBrainz';
    public const string SOURCE_DISCOGS = 'Discogs';
    public const string SOURCE_LASTFM = 'Last.fm';

    /**
     * Details with no column of their own, kept as custom fields. Label, writers
     * and ISWC are what the licensing reports (SoundExchange, ASCAP, BMI) read.
     */
    private const array CUSTOM_FIELDS = [
        self::FIELD_YEAR => 'Year',
        self::FIELD_LABEL => 'Label',
        self::FIELD_WRITERS => 'Writers',
        self::FIELD_ISWC => 'ISWC',
    ];

    /** One request for everything a release knows about its tracks, their ISRCs and their songwriters. */
    private const string RELEASE_INCLUDES = 'labels+release-groups+genres+recordings+isrcs'
        . '+recording-level-rels+work-rels+work-level-rels+artist-rels';

    /** The credits on a song that name who wrote it. */
    private const array WRITER_CREDITS = ['writer', 'composer', 'lyricist'];

    /** Below this, a MusicBrainz search result is a different recording that shares some words. */
    private const int MIN_MUSICBRAINZ_SCORE = 85;

    private const int FOUND_TTL_SECONDS = 3600;

    public function __construct(
        private readonly MusicBrainz $musicBrainz,
        private readonly Discogs $discogs,
        private readonly LastFm $lastFm,
        private readonly StationMediaRepository $mediaRepo,
        private readonly CustomFieldRepository $customFieldRepo,
        private readonly CacheInterface $cache,
        private readonly Client $httpClient,
    ) {
    }

    /**
     * @return array{
     *     fields: array<string, array{current: string|null, found: string|null, source: string|null}>,
     *     sources: string[],
     *     errors: string[]
     * }
     */
    public function lookup(Station $station, StationMedia $media): array
    {
        if ('' === trim($media->title ?? '') || '' === trim($media->artist ?? '')) {
            throw new RuntimeException('A track needs a title and an artist to be looked up.');
        }

        $found = [];
        $sources = [];
        $errors = [];

        $lookups = [self::SOURCE_MUSICBRAINZ => fn(): array => $this->fromMusicBrainz($media)];

        $discogsToken = trim($station->backend_config->media_lookup_discogs_token ?? '');
        if ('' !== $discogsToken) {
            $lookups[self::SOURCE_DISCOGS] = fn(): array => $this->fromDiscogs($media, $discogsToken);
        }
        if ($this->lastFm->hasApiKey()) {
            $lookups[self::SOURCE_LASTFM] = fn(): array => $this->fromLastFm($media);
        }

        foreach ($lookups as $source => $lookup) {
            // Every detail already has an answer from a source ahead of this one.
            if (count($found) === count(self::FIELDS)) {
                break;
            }

            try {
                $values = array_filter($lookup(), static fn(?string $value): bool => '' !== trim($value ?? ''));
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('%s lookup failed.', $source),
                    ['media' => $media->path, 'exception' => $e]
                );
                $errors[] = sprintf('%s could not be reached.', $source);
                continue;
            }

            $sources[] = $source;
            foreach ($values as $field => $value) {
                $found[$field] ??= ['value' => trim($value), 'source' => $source];
            }
        }

        $this->cache->set(self::foundKey($media), $found, self::FOUND_TTL_SECONDS);

        $current = $this->currentValues($media);

        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = [
                'current' => $current[$field],
                'found' => $found[$field]['value'] ?? null,
                'source' => $found[$field]['source'] ?? null,
            ];
        }

        return ['fields' => $fields, 'sources' => $sources, 'errors' => $errors];
    }

    /**
     * Save the details the operator accepted from the last lookup of this track.
     *
     * @param string[] $fields
     * @return string[] The details that were saved.
     */
    public function apply(StationMedia $media, array $fields): array
    {
        $found = $this->cache->get(self::foundKey($media));
        if (!is_array($found)) {
            throw new RuntimeException('Look this track up again; what was found has expired.');
        }

        $applied = [];
        $writeTags = false;

        foreach (array_intersect(self::FIELDS, $fields) as $field) {
            $value = $found[$field]['value'] ?? null;
            if (null === $value) {
                continue;
            }

            switch ($field) {
                case self::FIELD_ALBUM:
                    $media->album = $value;
                    $writeTags = true;
                    break;

                case self::FIELD_GENRE:
                    $media->genre = $value;
                    $writeTags = true;
                    break;

                case self::FIELD_ISRC:
                    $media->isrc = $value;
                    $writeTags = true;
                    break;

                case self::FIELD_YEAR:
                case self::FIELD_LABEL:
                case self::FIELD_WRITERS:
                case self::FIELD_ISWC:
                    $this->setCustomField($media, $field, $value);
                    break;

                case self::FIELD_ART:
                    $art = $this->download($value);
                    if (null === $art) {
                        continue 2;
                    }
                    $this->mediaRepo->writeAlbumArt($media, $art);
                    $writeTags = true;
                    break;
            }

            $applied[] = $field;
        }

        if ([] === $applied) {
            return [];
        }

        $this->em->persist($media);
        $this->em->flush();

        // The file carries what the library says, as it does after an edit.
        if ($writeTags && !$this->mediaRepo->writeToFile($media)) {
            throw new RuntimeException('Could not write media metadata to file.');
        }
        $this->em->flush();

        return $applied;
    }

    /** A track uploaded with "look up on upload" switched on. */
    public function __invoke(LookupMediaMetadataMessage $message): void
    {
        $station = $this->em->find(Station::class, $message->station_id);
        $media = $this->em->find(StationMedia::class, $message->media_id);

        // Only music is in these catalogues; a show, an ID or a promo is not.
        if (!($station instanceof Station) || !($media instanceof StationMedia) || 'music' !== $media->type) {
            return;
        }

        try {
            $this->fillMissing($station, $media);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Looking up an uploaded track failed.',
                ['media' => $media->path, 'exception' => $e]
            );
        }
    }

    /**
     * Look a track up and fill in only what it is missing, for uploads.
     *
     * @return string[] The details that were saved.
     */
    public function fillMissing(Station $station, StationMedia $media): array
    {
        $result = $this->lookup($station, $media);

        $missing = [];
        foreach ($result['fields'] as $field => $values) {
            // A genre found online is a suggestion to review, never filled in unseen.
            if (
                self::FIELD_GENRE !== $field
                && null === $values['current']
                && null !== $values['found']
            ) {
                $missing[] = $field;
            }
        }

        return [] === $missing ? [] : $this->apply($media, $missing);
    }

    public function hasLastFmKey(): bool
    {
        return $this->lastFm->hasApiKey();
    }

    /**
     * What the licensing reports need that a track has no column for: the label,
     * the songwriters and the ISWC saved on each track of a media folder.
     *
     * @return array<int, array{label: string|null, writers: string|null, iswc: string|null}> by media id
     */
    public function savedDetails(StorageLocation $storageLocation): array
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(e.media) AS media_id, cf.short_name, e.value
                FROM App\Entity\StationMediaCustomField e
                JOIN e.field cf
                JOIN e.media m
                WHERE m.storage_location = :storageLocation
                AND cf.short_name IN (:fields)
            DQL
        )->setParameter('storageLocation', $storageLocation)
            ->setParameter('fields', [self::FIELD_LABEL, self::FIELD_WRITERS, self::FIELD_ISWC])
            ->getArrayResult();

        $details = [];
        foreach ($rows as $row) {
            $value = trim((string)($row['value'] ?? ''));
            if ('' === $value) {
                continue;
            }

            $id = (int)$row['media_id'];
            $saved = $details[$id] ?? [
                self::FIELD_LABEL => null,
                self::FIELD_WRITERS => null,
                self::FIELD_ISWC => null,
            ];

            match ($row['short_name']) {
                self::FIELD_LABEL => $saved[self::FIELD_LABEL] = $value,
                self::FIELD_WRITERS => $saved[self::FIELD_WRITERS] = $value,
                default => $saved[self::FIELD_ISWC] = $value,
            };

            $details[$id] = $saved;
        }

        return $details;
    }

    /**
     * The custom fields that hold year, label, writers and ISWC, created the first time they are needed.
     *
     * @return array<string, CustomField>
     */
    public function customFields(): array
    {
        $repo = $this->em->getRepository(CustomField::class);

        $fields = [];
        foreach (self::CUSTOM_FIELDS as $field => $name) {
            $record = $repo->findOneBy(['short_name' => $field]);
            if (!($record instanceof CustomField)) {
                $record = new CustomField();
                $record->name = $name;
                $record->short_name = $field;
                $this->em->persist($record);
                $this->em->flush();
            }
            $fields[$field] = $record;
        }

        return $fields;
    }

    private static function foundKey(StationMedia $media): string
    {
        return 'media_lookup.' . $media->unique_id;
    }

    /**
     * @return array<string, string|null>
     */
    private function currentValues(StationMedia $media): array
    {
        $custom = $this->customFieldRepo->getCustomFields($media);
        $text = static fn(mixed $value): ?string => '' === trim((string)($value ?? '')) ? null : trim((string)$value);

        return [
            self::FIELD_ALBUM => $text($media->album),
            self::FIELD_GENRE => $text($media->genre),
            self::FIELD_YEAR => $text($custom[self::FIELD_YEAR] ?? null),
            self::FIELD_LABEL => $text($custom[self::FIELD_LABEL] ?? null),
            self::FIELD_ISRC => $text($media->isrc),
            self::FIELD_WRITERS => $text($custom[self::FIELD_WRITERS] ?? null),
            self::FIELD_ISWC => $text($custom[self::FIELD_ISWC] ?? null),
            // Cover art is a picture, not text: what matters is whether the track has one.
            self::FIELD_ART => $media->art_updated_at > 0 ? 'yes' : null,
        ];
    }

    private function setCustomField(StationMedia $media, string $field, string $value): void
    {
        $customField = $this->customFields()[$field];

        foreach ($media->custom_fields as $row) {
            /** @var StationMediaCustomField $row */
            if ($row->field->id === $customField->id) {
                $row->value = $value;
                $this->em->persist($row);
                return;
            }
        }

        $row = new StationMediaCustomField($media, $customField);
        $row->value = $value;
        $this->em->persist($row);
        $media->custom_fields->add($row);
    }

    /**
     * @return array<string, string|null>
     */
    private function fromMusicBrainz(StationMedia $media): array
    {
        // A file tagged from MusicBrainz names its own release and track, so
        // there is nothing to search for and nothing to match by name.
        $tags = $media->extra_metadata->toArray(true) ?? [];
        $releaseId = self::mbid($tags['musicbrainz album id'] ?? null);
        $trackId = self::mbid($tags['musicbrainz release track id'] ?? null);

        if (null !== $releaseId && null !== $trackId) {
            $release = $this->release($releaseId);
            $recording = self::recordingOnRelease(
                $release,
                static fn(array $track): bool => $trackId === ($track['id'] ?? null)
            );

            if (null !== $recording) {
                return self::fromRelease($release, $recording);
            }
        }

        $recordings = array_values(array_filter(
            $this->musicBrainz->findRecordingsForSong($media),
            fn(array $recording): bool => (int)($recording['score'] ?? 0) >= self::MIN_MUSICBRAINZ_SCORE
                && self::sameText($recording['title'] ?? '', $media->title ?? '')
                && $this->sameArtist(
                    implode(' ', array_column($recording['artist-credit'] ?? [], 'name')),
                    $media->artist ?? ''
                )
        ));

        if ([] === $recordings) {
            return [];
        }

        // Each release remembers the recording it carries: an ISRC and a first
        // release date belong to one recording, and a live take or a re-record
        // of the same song has its own.
        $releases = [];
        foreach ($recordings as $i => $recording) {
            foreach ($recording['releases'] ?? [] as $release) {
                $releases[] = [...$release, 'recording' => $i];
            }
        }

        $found = self::bestRelease($releases, $media->album);
        $recording = $recordings[$found['recording'] ?? 0];

        if (null === $found) {
            return [
                self::FIELD_ISRC => $recording['isrcs'][0] ?? null,
                self::FIELD_YEAR => self::year($recording['first-release-date'] ?? null),
            ];
        }

        $release = $this->release($found['id']);

        return self::fromRelease(
            $release,
            self::recordingOnRelease(
                $release,
                static fn(array $track): bool => $recording['id'] === ($track['recording']['id'] ?? null)
            ) ?? $recording
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $id): array
    {
        return $this->musicBrainz->makeRequest('release/' . $id, ['inc' => self::RELEASE_INCLUDES]);
    }

    /**
     * @param array<string, mixed> $release
     * @param callable(array<string, mixed>): bool $isTrack
     * @return array<string, mixed>|null
     */
    private static function recordingOnRelease(array $release, callable $isTrack): ?array
    {
        foreach ($release['media'] ?? [] as $medium) {
            foreach ($medium['tracks'] ?? [] as $track) {
                if ($isTrack($track)) {
                    return $track['recording'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * What a release and one recording on it say about a track.
     *
     * @param array<string, mixed> $release
     * @param array<string, mixed> $recording
     * @return array<string, string|null>
     */
    private static function fromRelease(array $release, array $recording): array
    {
        $genres = $release['release-group']['genres'] ?? [];
        if ([] === $genres) {
            $genres = $release['genres'] ?? [];
        }
        usort($genres, static fn(array $a, array $b): int => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        // The song a recording performs carries its writers and its ISWC. A
        // medley performs several; all of their writers are its writers.
        $writers = [];
        $iswc = null;
        foreach ($recording['relations'] ?? [] as $relation) {
            if ('work' !== ($relation['target-type'] ?? null) || 'performance' !== ($relation['type'] ?? null)) {
                continue;
            }

            $work = $relation['work'] ?? [];
            $iswc ??= $work['iswcs'][0] ?? null;

            foreach ($work['relations'] ?? [] as $credit) {
                $name = trim((string)($credit['artist']['name'] ?? ''));
                if ('' !== $name && in_array($credit['type'] ?? null, self::WRITER_CREDITS, true)) {
                    $writers[$name] = true;
                }
            }
        }

        // MusicBrainz says whether the Cover Art Archive holds a front cover
        // for this release, so the picture is not fetched until it is accepted.
        $art = ($release['cover-art-archive']['front'] ?? false) && isset($release['id'])
            ? sprintf('%srelease/%s/front-500', MusicBrainz::COVER_ART_ARCHIVE_BASE_URL, $release['id'])
            : null;

        return [
            self::FIELD_ALBUM => $release['title'] ?? null,
            self::FIELD_GENRE => isset($genres[0]['name']) ? ucwords((string)$genres[0]['name']) : null,
            self::FIELD_YEAR => self::year(
                $recording['first-release-date']
                    ?? $release['release-group']['first-release-date']
                    ?? $release['date']
                    ?? null
            ),
            self::FIELD_LABEL => $release['label-info'][0]['label']['name'] ?? null,
            self::FIELD_ISRC => $recording['isrcs'][0] ?? null,
            self::FIELD_WRITERS => [] === $writers ? null : implode(', ', array_keys($writers)),
            self::FIELD_ISWC => $iswc,
            self::FIELD_ART => $art,
        ];
    }

    private static function mbid(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));

        return 1 === preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $value) ? $value : null;
    }

    /**
     * The release a listener would call "the album": the one the track already
     * names if there is one, otherwise an official studio album before a single,
     * a compilation or a live record, and the earliest of those.
     *
     * @param array<array<string, mixed>> $releases
     * @return array<string, mixed>|null
     */
    private static function bestRelease(array $releases, ?string $album): ?array
    {
        if ([] === $releases) {
            return null;
        }

        $rank = static function (array $release) use ($album): array {
            $group = $release['release-group'] ?? [];
            return [
                null !== $album && self::sameText($release['title'] ?? '', $album) ? 0 : 1,
                'Official' === ($release['status'] ?? null) ? 0 : 1,
                [] === ($group['secondary-types'] ?? []) ? 0 : 1,
                'Album' === ($group['primary-type'] ?? null) ? 0 : 1,
                (string)($release['date'] ?? '') ?: '9999',
            ];
        };

        usort($releases, static fn(array $a, array $b): int => $rank($a) <=> $rank($b));

        return $releases[0];
    }

    /**
     * @return array<string, string|null>
     */
    private function fromDiscogs(StationMedia $media, string $token): array
    {
        $releases = $this->discogs->findReleasesForTrack($token, $media->artist ?? '', $media->title ?? '');

        foreach ($releases as $release) {
            // Discogs titles a release "Artist - Album".
            [$artist, $album] = array_pad(explode(' - ', (string)($release['title'] ?? ''), 2), 2, null);
            if (null === $album || !$this->sameArtist($artist, $media->artist ?? '')) {
                continue;
            }

            $cover = (string)($release['cover_image'] ?? '');

            return [
                self::FIELD_ALBUM => $album,
                // The style ("Gospel") says more than the genre above it ("Funk / Soul").
                self::FIELD_GENRE => $release['style'][0] ?? $release['genre'][0] ?? null,
                self::FIELD_YEAR => self::year((string)($release['year'] ?? '')),
                self::FIELD_LABEL => $release['label'][0] ?? null,
                self::FIELD_ART => str_contains($cover, 'spacer.gif') ? null : $cover,
            ];
        }

        return [];
    }

    /**
     * @return array<string, string|null>
     */
    private function fromLastFm(StationMedia $media): array
    {
        $response = $this->lastFm->makeRequest(
            'track.getInfo',
            [
                'artist' => $media->artist,
                'track' => $media->title,
                'autocorrect' => 1,
            ]
        );

        $track = $response['track'] ?? [];

        $art = null;
        foreach (array_reverse($track['album']['image'] ?? []) as $image) {
            if (!empty($image['#text'])) {
                $art = (string)$image['#text'];
                break;
            }
        }

        $genre = $track['toptags']['tag'][0]['name'] ?? null;

        return [
            self::FIELD_ALBUM => $track['album']['title'] ?? null,
            self::FIELD_GENRE => null === $genre ? null : ucwords((string)$genre),
            self::FIELD_ART => $art,
        ];
    }

    private function download(string $url): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $url,
                [
                    RequestOptions::TIMEOUT => 15,
                    RequestOptions::HTTP_ERRORS => true,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('Cover art could not be downloaded.', ['url' => $url, 'exception' => $e]);
            return null;
        }

        $body = (string)$response->getBody();
        return '' === $body ? null : $body;
    }

    private static function year(?string $date): ?string
    {
        return preg_match('/^(\d{4})/', (string)$date, $matches) && '0000' !== $matches[1] ? $matches[1] : null;
    }

    /** Letters and digits only, so punctuation, case and spacing never decide a match. */
    private static function plain(string $text): string
    {
        $text = mb_strtolower($text);
        // "(Live)", "[Radio Edit]", "feat. ..." describe a version, not the song or the artist.
        $text = (string)preg_replace('/[(\[].*?[)\]]|\b(feat|ft|featuring)\b.*$/u', ' ', $text);

        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
    }

    private static function sameText(string $a, string $b): bool
    {
        return '' !== self::plain($a) && self::plain($a) === self::plain($b);
    }

    /** An artist credit often lists more names than the tag does ("A & B" for "A"). */
    private function sameArtist(?string $found, string $tagged): bool
    {
        $found = self::plain((string)$found);
        $tagged = self::plain($tagged);

        return '' !== $found && '' !== $tagged
            && (str_contains($found, $tagged) || str_contains($tagged, $found));
    }
}
