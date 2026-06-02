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
 * Named block of hours that share one clock template (PR10).
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_clock_dayparts'),
    Auditable
]
final class StationClockDaypart implements
    Stringable,
    StationAwareInterface,
    IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne(inversedBy: 'clock_dayparts'),
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        ORM\ManyToOne(inversedBy: 'dayparts'),
        ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')
    ]
    public StationClockWheelTemplate $template;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $template_id;

    #[
        OA\Property(example: 'Morning Drive'),
        ORM\Column(length: 100),
        Assert\NotBlank,
        Assert\Length(max: 100)
    ]
    public string $name {
        set => $this->truncateString(trim($value), 100);
    }

    /** Inclusive start hour (0–23) in station local time. */
    #[
        OA\Property(example: 6),
        ORM\Column(type: 'smallint', options: ['unsigned' => true]),
        Assert\Range(min: 0, max: 23)
    ]
    public int $start_hour = 6;

    /** Inclusive end hour (0–23); may be before start_hour for overnight spans. */
    #[
        OA\Property(example: 10),
        ORM\Column(type: 'smallint', options: ['unsigned' => true]),
        Assert\Range(min: 0, max: 23)
    ]
    public int $end_hour = 10;

    #[
        OA\Property(example: '#e87722', nullable: true),
        ORM\Column(length: 7, nullable: true),
        Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')
    ]
    public ?string $color = null;

    #[
        OA\Property(example: true),
        ORM\Column
    ]
    public bool $is_active = true;

    /**
     * When true, separation/burn settings on this daypart replace per-wheel values
     * for all hourly instances generated from the daypart.
     */
    #[
        OA\Property(example: false),
        ORM\Column
    ]
    public bool $separation_override_enabled = false;

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
            targetEntity: StationClockWheel::class,
            mappedBy: 'daypart',
            cascade: ['remove'],
            orphanRemoval: true,
            fetch: 'EXTRA_LAZY'
        )
    ]
    public private(set) Collection $wheels;

    public function __construct(Station $station, StationClockWheelTemplate $template)
    {
        $this->station = $station;
        $this->template = $template;
        $this->wheels = new ArrayCollection();
    }

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    public function syncReadOnlyForeignKeys(): void
    {
        $this->station_id = $this->station->id;
        $this->template_id = $this->template->id;
    }

    public function __toString(): string
    {
        return isset($this->name) ? $this->name : 'Clock Daypart';
    }
}
