<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration\Schema;

use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Enums\SmartBlockType;
use App\Utilities\Types;
use JsonSerializable;

/**
 * A smart block in a playlist configuration file: its rules and its calendar times.
 */
final class SmartBlockEntry implements JsonSerializable
{
    /**
     * @param PlaylistSmartBlockCriterionEntry[] $criteria
     * @param PlaylistScheduleEntry[] $schedules
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $isEnabled = true,
        public readonly int $weight = 3,
        public readonly SmartBlockType $type = SmartBlockType::Dynamic,
        public readonly SmartBlockMatchType $matchType = SmartBlockMatchType::All,
        public readonly ?int $limit = null,
        public readonly SmartBlockLimitType $limitType = SmartBlockLimitType::Tracks,
        public readonly SmartBlockSortOrder $sortOrder = SmartBlockSortOrder::Random,
        public readonly bool $avoidDuplicates = true,
        public array $criteria = [],
        public array $schedules = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: Types::string($data['name'] ?? 'Imported Smart Block'),
            isEnabled: Types::bool($data['is_enabled'] ?? true),
            weight: Types::int($data['weight'] ?? 3),
            type: SmartBlockType::tryFrom(Types::string($data['type'] ?? 'dynamic')) ?? SmartBlockType::Dynamic,
            matchType: SmartBlockMatchType::tryFrom(Types::string($data['match_type'] ?? 'all'))
                ?? SmartBlockMatchType::All,
            limit: Types::intOrNull($data['limit'] ?? null),
            limitType: SmartBlockLimitType::tryFrom(Types::string($data['limit_type'] ?? 'tracks'))
                ?? SmartBlockLimitType::Tracks,
            sortOrder: SmartBlockSortOrder::tryFrom(Types::string($data['sort_order'] ?? 'random'))
                ?? SmartBlockSortOrder::Random,
            avoidDuplicates: Types::bool($data['avoid_duplicates'] ?? true),
            criteria: array_map(
                static fn(mixed $item): PlaylistSmartBlockCriterionEntry =>
                    PlaylistSmartBlockCriterionEntry::fromArray(Types::array($item)),
                Types::array($data['criteria'] ?? [])
            ),
            schedules: array_map(
                static fn(mixed $item): PlaylistScheduleEntry => PlaylistScheduleEntry::fromArray(Types::array($item)),
                Types::array($data['schedules'] ?? [])
            ),
        );
    }

    /**
     * A smart block from a file written when smart blocks were playlists
     * (a playlist entry with "is_smart_block": true).
     */
    public static function fromLegacyPlaylist(array $data): self
    {
        $config = Types::array($data['config'] ?? []);

        return self::fromArray([
            'name' => $data['name'] ?? 'Imported Smart Block',
            'is_enabled' => $config['is_enabled'] ?? true,
            'weight' => $config['weight'] ?? 3,
            'type' => $config['smart_block_type'] ?? 'dynamic',
            'match_type' => $config['smart_block_match_type'] ?? 'all',
            'limit' => $config['smart_block_limit'] ?? null,
            'limit_type' => $config['smart_block_limit_type'] ?? 'tracks',
            'sort_order' => $config['smart_block_sort_order'] ?? 'random',
            'avoid_duplicates' => $config['smart_block_avoid_duplicates'] ?? true,
            'criteria' => $data['smart_block_criteria'] ?? [],
            'schedules' => $data['schedules'] ?? [],
        ]);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'name' => $this->name,
            'is_enabled' => $this->isEnabled,
            'weight' => $this->weight,
            'type' => $this->type->value,
            'match_type' => $this->matchType->value,
            'limit' => $this->limit,
            'limit_type' => $this->limitType->value,
            'sort_order' => $this->sortOrder->value,
            'avoid_duplicates' => $this->avoidDuplicates,
            'criteria' => $this->criteria,
            'schedules' => $this->schedules,
        ];
    }
}
