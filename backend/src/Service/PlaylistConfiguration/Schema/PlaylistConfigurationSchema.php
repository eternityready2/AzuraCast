<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration\Schema;

use App\Entity\Station;
use App\Service\PlaylistConfiguration\PlaylistConfigurationType;
use App\Utilities\Time;
use App\Utilities\Types;
use JsonSerializable;

final class PlaylistConfigurationSchema implements JsonSerializable
{
    /**
     * Version 1 remains compatible with upstream's initial playlist export format.
     * Fork-specific fields are additive and optional so upstream v1 dumps remain importable.
     */
    public const int VERSION = 1;

    /**
     * @param MediaEntry[] $mediaEntries
     * @param PlaylistEntry[] $playlistEntries
     * @param SmartBlockEntry[] $smartBlockEntries
     */
    public function __construct(
        public readonly PlaylistConfigurationType $type,
        public readonly Station $station,
        public array $mediaEntries,
        public array $playlistEntries,
        public array $smartBlockEntries = [],
    ) {
    }

    public static function fromArray(array $data, Station $station): self
    {
        // A file written when smart blocks were playlists lists them under
        // "playlists" with "is_smart_block"; they come in as smart blocks.
        $playlistEntries = [];
        $smartBlockEntries = [];
        foreach (Types::array($data['playlists'] ?? []) as $item) {
            $item = Types::array($item);
            if (Types::bool(Types::array($item['config'] ?? [])['is_smart_block'] ?? false)) {
                $smartBlockEntries[] = SmartBlockEntry::fromLegacyPlaylist($item);
            } else {
                $playlistEntries[] = PlaylistEntry::fromArray($item);
            }
        }
        foreach (Types::array($data['smart_blocks'] ?? []) as $item) {
            $smartBlockEntries[] = SmartBlockEntry::fromArray(Types::array($item));
        }

        return new self(
            type: PlaylistConfigurationType::from(Types::string($data['type'] ?? null)),
            station: $station,
            mediaEntries: array_map(
                static fn(mixed $item): MediaEntry => MediaEntry::fromArray(Types::array($item)),
                Types::array($data['media'] ?? [])
            ),
            playlistEntries: $playlistEntries,
            smartBlockEntries: $smartBlockEntries,
        );
    }

    public function jsonSerialize(): mixed
    {
        $backendConfig = $this->station->backend_config;

        return [
            'schema_version' => self::VERSION,
            'type' => $this->type->value,
            'exported_at' => Time::nowUtc()->toIso8601String(),
            'station' => [
                'name' => $this->station->name,
                'timezone' => $this->station->timezone,
                'requests_only_via_playlists' => property_exists($this->station, 'requests_only_via_playlists')
                    ? $this->station->requests_only_via_playlists
                    : false,
                'backend_config' => [
                    'duplicate_prevention_time_range' => $backendConfig->duplicate_prevention_time_range,
                    'autodj_queue_length' => $backendConfig->autodj_queue_length,
                    'crossfade' => $backendConfig->crossfade,
                    'crossfade_type' => $backendConfig->crossfade_type,
                    'crossfade_smart_high' => $backendConfig->crossfade_smart_high,
                    'crossfade_smart_medium' => $backendConfig->crossfade_smart_medium,
                    'crossfade_smart_margin' => $backendConfig->crossfade_smart_margin,
                ],
            ],
            'media' => array_values($this->mediaEntries),
            'playlists' => array_values($this->playlistEntries),
            'smart_blocks' => array_values($this->smartBlockEntries),
        ];
    }
}
