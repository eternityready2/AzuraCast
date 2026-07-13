<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Attributes\Auditable;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_holiday_overrides'),
    ORM\UniqueConstraint(name: 'UNIQ_holiday_station_date', columns: ['station_id', 'override_date']),
    Auditable
]
final class StationHolidayOverride implements
    Stringable,
    StationAwareInterface,
    IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne(inversedBy: 'holiday_overrides'),
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        OA\Property(example: 'Christmas Day'),
        ORM\Column(length: 100),
        Assert\NotBlank,
        Assert\Length(max: 100)
    ]
    public string $name {
        set => $this->truncateString(trim($value), 100);
    }

    #[
        OA\Property(example: '2026-12-25'),
        ORM\Column(type: 'date_immutable')
    ]
    public DateTimeImmutable $override_date;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'clock_wheel_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationClockWheel $clock_wheel = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $clock_wheel_id = null;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationPlaylist $playlist = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $playlist_id = null;

    #[ORM\Column]
    public bool $is_active = true;

    #[
        OA\Property(example: 'Special Christmas wheel', nullable: true),
        ORM\Column(length: 255, nullable: true),
        Assert\Length(max: 255)
    ]
    public ?string $notes = null {
        set => $this->truncateNullableString($value, 255);
    }

    public function __construct(Station $station, DateTimeImmutable $overrideDate)
    {
        $this->station = $station;
        $this->override_date = $overrideDate;
    }

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    public function syncReadOnlyForeignKeys(): void
    {
        $this->station_id = $this->station->id;
        $this->clock_wheel_id = $this->clock_wheel?->id;
        $this->playlist_id = $this->playlist?->id;
    }

    public function __toString(): string
    {
        return isset($this->name) ? $this->name : 'Holiday Override';
    }
}
