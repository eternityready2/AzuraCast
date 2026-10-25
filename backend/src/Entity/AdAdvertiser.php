<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Serializer\Attribute as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A business or ministry that buys airtime. Everything they book hangs off this record.
 */
#[
    OA\Schema(type: "object"),
    ORM\Entity,
    ORM\Table(name: 'ad_advertisers'),
    Attributes\Auditable
]
final class AdAdvertiser implements Stringable, StationAwareInterface, IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE'),
        Serializer\Ignore
    ]
    public Station $station;

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    #[
        OA\Property(example: 'Grace Insurance'),
        ORM\Column(length: 190),
        Assert\NotBlank
    ]
    public string $name = '' {
        set => $this->truncateString($value, 190);
    }

    #[OA\Property(example: 'Dale Means'), ORM\Column(length: 190, nullable: true)]
    public ?string $contact_name = null {
        set => $this->truncateNullableString($value, 190);
    }

    #[OA\Property(example: 'ads@example.com'), ORM\Column(length: 190, nullable: true)]
    public ?string $email = null {
        set => $this->truncateNullableString($value, 190);
    }

    #[OA\Property(example: '816-555-0134'), ORM\Column(length: 60, nullable: true)]
    public ?string $phone = null {
        set => $this->truncateNullableString($value, 60);
    }

    #[OA\Property(example: 'https://example.com'), ORM\Column(length: 255, nullable: true)]
    public ?string $website = null {
        set => $this->truncateNullableString($value, 255);
    }

    /**
     * Churches and nonprofits get the discounted rate on the rate card.
     */
    #[OA\Property(example: false), ORM\Column(options: ['default' => false])]
    public bool $is_nonprofit = false;

    #[OA\Property(example: 'Pays by check, net 30.'), ORM\Column(type: 'text', nullable: true)]
    public ?string $notes = null;

    #[OA\Property(example: 1770000000), ORM\Column]
    public int $created_at;

    /** @var Collection<int, AdOrder> */
    #[
        ORM\OneToMany(targetEntity: AdOrder::class, mappedBy: 'advertiser'),
        Serializer\Ignore
    ]
    public Collection $orders;

    public function __construct(Station $station)
    {
        $this->station = $station;
        $this->created_at = time();
        $this->orders = new ArrayCollection();
    }

    /** How many bookings hang off this advertiser, for the list screen. */
    public function getOrdersCount(): int
    {
        return $this->orders->count();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
