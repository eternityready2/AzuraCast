<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Attributes\Auditable;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Reusable hour layout (slots) shared by clock wheel instances (PR10).
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_clock_wheel_templates'),
    Auditable
]
final class StationClockWheelTemplate implements
    Stringable,
    StationAwareInterface,
    IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne(inversedBy: 'clock_wheel_templates'),
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        OA\Property(example: 'Morning Music'),
        ORM\Column(length: 100),
        Assert\NotBlank,
        Assert\Length(max: 100)
    ]
    public string $name {
        set => $this->truncateString(trim($value), 100);
    }

    #[
        OA\Property(example: '#e87722'),
        ORM\Column(length: 7),
        Assert\NotBlank,
        Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')
    ]
    public string $color = '#e87722';

    /** Default separation for wheels linked to this template when the wheel has no rules enabled (PR9). */
    #[
        OA\Property(example: false),
        ORM\Column
    ]
    public bool $separation_enabled = false;

    #[
        OA\Property(example: 45),
        ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])
    ]
    public ?int $separation_artist_minutes = 45;

    #[
        OA\Property(example: 90),
        ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])
    ]
    public ?int $separation_title_minutes = 90;

    #[
        OA\Property(example: 3),
        ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])
    ]
    public ?int $burn_rate_max_plays_24h = null;

    #[
        ORM\OneToMany(
            targetEntity: StationClockWheelTemplateSlot::class,
            mappedBy: 'template',
            cascade: ['persist', 'remove'],
            orphanRemoval: true,
            fetch: 'EXTRA_LAZY'
        ),
        ORM\OrderBy(['position_seconds' => 'ASC', 'slot_order' => 'ASC'])
    ]
    public private(set) Collection $slots;

    #[
        ORM\OneToMany(
            targetEntity: StationClockDaypart::class,
            mappedBy: 'template',
            fetch: 'EXTRA_LAZY'
        )
    ]
    public private(set) Collection $dayparts;

    #[
        ORM\OneToMany(
            targetEntity: StationClockWheel::class,
            mappedBy: 'template',
            fetch: 'EXTRA_LAZY'
        )
    ]
    public private(set) Collection $wheels;

    public function __construct(Station $station)
    {
        $this->station = $station;
        $this->slots = new ArrayCollection();
        $this->dayparts = new ArrayCollection();
        $this->wheels = new ArrayCollection();
    }

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    public function addSlot(StationClockWheelTemplateSlot $slot): void
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->template = $this;
        }
    }

    /**
     * Back-fill insertable=false mirror columns after flush (serializer reads them on create).
     */
    public function syncReadOnlyForeignKeys(): void
    {
        $this->station_id = $this->station->id;
    }

    public function __toString(): string
    {
        return isset($this->name) ? $this->name : 'Clock Wheel Template';
    }
}
