<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Attributes\Auditable;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;

/**
 * A Smart Block: songs chosen from the media library by rules (genre, BPM, a custom
 * field, when it last played...), scheduled from the calendar or used in a clock
 * wheel slot. It is its own thing, not a playlist: the AutoDJ plays it through
 * {@see \App\Radio\AutoDJ\SmartBlockScheduler}, straight from its rules.
 *
 * - Dynamic: the rules are applied at air time, so new matching uploads play.
 * - Static: the rules are applied when it is saved (or "Generate Now"); the
 *   resulting list in {@see StationSmartBlockMedia} plays until regenerated.
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_smart_blocks'),
    Auditable
]
final class StationSmartBlock implements Stringable, StationAwareInterface, IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        OA\Property(example: 'Upbeat Worship'),
        ORM\Column(length: 200)
    ]
    public string $name {
        set => $this->truncateString(trim($value), 200);
    }

    #[
        OA\Property(example: true),
        ORM\Column(options: ['default' => true])
    ]
    public bool $is_enabled = true;

    /** Kept from the playlist era; shown on the Smart Blocks page. */
    #[
        OA\Property(example: 3),
        ORM\Column(options: ['default' => 3])
    ]
    public int $weight = 3;

    #[
        OA\Property(example: 'dynamic'),
        ORM\Column(type: 'string', length: 10, enumType: SmartBlockType::class, options: ['default' => 'dynamic'])
    ]
    public SmartBlockType $type = SmartBlockType::Dynamic;

    #[
        OA\Property(example: 'all'),
        ORM\Column(type: 'string', length: 10, enumType: SmartBlockMatchType::class, options: ['default' => 'all'])
    ]
    public SmartBlockMatchType $match_type = SmartBlockMatchType::All;

    #[
        OA\Property(example: 50, nullable: true),
        ORM\Column(name: 'limit_value', nullable: true)
    ]
    public ?int $limit = null {
        set (int|string|null $value) {
            $limit = (null === $value || '' === $value) ? 0 : (int)$value;
            $this->limit = $limit > 0 ? $limit : null;
        }
    }

    #[
        OA\Property(example: 'tracks'),
        ORM\Column(type: 'string', length: 10, enumType: SmartBlockLimitType::class, options: ['default' => 'tracks'])
    ]
    public SmartBlockLimitType $limit_type = SmartBlockLimitType::Tracks;

    #[
        OA\Property(example: 'random'),
        ORM\Column(type: 'string', length: 20, enumType: SmartBlockSortOrder::class, options: ['default' => 'random'])
    ]
    public SmartBlockSortOrder $sort_order = SmartBlockSortOrder::Random;

    #[
        OA\Property(example: true),
        ORM\Column(options: ['default' => true])
    ]
    public bool $avoid_duplicates = true;

    /** @var Collection<int, StationSmartBlockCriteria> */
    #[
        ORM\OneToMany(targetEntity: StationSmartBlockCriteria::class, mappedBy: 'smart_block', fetch: 'EXTRA_LAZY'),
        ORM\OrderBy(['weight' => 'ASC'])
    ]
    public private(set) Collection $criteria;

    /** @var Collection<int, StationSmartBlockMedia> */
    #[ORM\OneToMany(targetEntity: StationSmartBlockMedia::class, mappedBy: 'smart_block', fetch: 'EXTRA_LAZY')]
    public private(set) Collection $media_items;

    /** @var Collection<int, StationSchedule> */
    #[ORM\OneToMany(targetEntity: StationSchedule::class, mappedBy: 'smart_block', fetch: 'EXTRA_LAZY')]
    public private(set) Collection $schedule_items;

    public function __construct(Station $station)
    {
        $this->station = $station;
        $this->criteria = new ArrayCollection();
        $this->media_items = new ArrayCollection();
        $this->schedule_items = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->station . ' Smart Block: ' . $this->name;
    }
}
