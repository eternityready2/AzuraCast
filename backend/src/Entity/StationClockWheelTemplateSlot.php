<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotPoolModes;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_clock_wheel_template_slots')
]
final class StationClockWheelTemplateSlot implements IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;

    #[
        ORM\ManyToOne(targetEntity: StationClockWheelTemplate::class, inversedBy: 'slots'),
        ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public StationClockWheelTemplate $template;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $template_id;

    #[
        ORM\ManyToOne(targetEntity: StationPlaylist::class),
        ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationPlaylist $playlist = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $playlist_id = null;

    #[
        OA\Property(example: 'music', nullable: true),
        ORM\Column(type: 'string', length: 20, nullable: true, enumType: ClockWheelSlotTypes::class)
    ]
    public ?ClockWheelSlotTypes $type = ClockWheelSlotTypes::Music;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationMediaCategory $category = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $category_id = null;

    #[
        OA\Property(example: 'random'),
        ORM\Column(type: 'string', length: 30, enumType: ClockWheelSlotAlgorithms::class)
    ]
    public ClockWheelSlotAlgorithms $algorithm = ClockWheelSlotAlgorithms::Random;

    #[
        OA\Property(example: 'restrict_pool'),
        ORM\Column(type: 'string', length: 30, enumType: ClockWheelSlotPoolModes::class)
    ]
    public ClockWheelSlotPoolModes $pool_mode = ClockWheelSlotPoolModes::RestrictPool;

    #[ORM\Column]
    public bool $separation_override_enabled = false;

    #[ORM\Column(nullable: true)]
    public ?int $separation_artist_minutes = null;

    #[ORM\Column(nullable: true)]
    public ?int $separation_title_minutes = null;

    #[
        OA\Property(example: 0),
        ORM\Column(type: 'smallint', options: ['unsigned' => true]),
        Assert\Range(min: 0, max: 3599)
    ]
    public int $position_seconds = 0;

    #[
        OA\Property(example: 0),
        ORM\Column(type: 'smallint')
    ]
    public int $slot_order = 0;

    #[
        OA\Property(example: 30, nullable: true),
        ORM\Column(type: 'smallint', nullable: true),
        Assert\PositiveOrZero
    ]
    public ?int $duration_seconds = null;

    #[ORM\Column]
    public bool $is_hard_anchor = false;

    #[
        ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true]),
        Assert\Range(min: 0, max: 100)
    ]
    public ?int $research_score = null;

    #[
        ORM\Column(length: 20, nullable: true),
        Assert\Length(max: 20)
    ]
    public ?string $sound_code = null;

    public function __construct(StationClockWheelTemplate $template)
    {
        $this->template = $template;
    }

    public function syncReadOnlyForeignKeys(): void
    {
        $this->template_id = $this->template->id;
        $this->playlist_id = $this->playlist?->id;
        $this->category_id = $this->category?->id;
    }
}
