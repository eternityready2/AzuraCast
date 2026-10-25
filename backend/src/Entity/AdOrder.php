<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\AdOrderStatus;
use App\Entity\Enums\AdProducts;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Serializer\Attribute as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One piece of sold airtime: who bought it, what they bought, when it runs and what it costs.
 *
 * An order owns the sponsor playlist that actually delivers it. The station operator never
 * touches that playlist; {@see \App\Service\Ads\AdOrderPlayoutSync} keeps it in step with
 * the order, and the existing sponsor-guarantee machinery makes sure the spots per day land.
 */
#[
    OA\Schema(type: "object"),
    ORM\Entity,
    ORM\Table(name: 'ad_orders'),
    Attributes\Auditable
]
final class AdOrder implements Stringable, StationAwareInterface, IdentifiableEntityInterface
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
        ORM\ManyToOne(inversedBy: 'orders'),
        ORM\JoinColumn(name: 'advertiser_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE'),
        Serializer\Ignore
    ]
    public AdAdvertiser $advertiser;

    /**
     * The sponsor playlist this order drives. Created on first approval, removed with the order.
     */
    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL'),
        Serializer\Ignore
    ]
    public ?StationPlaylist $playlist = null;

    /** The uploaded spot audio. Null while an order is still waiting on creative. */
    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL'),
        Serializer\Ignore
    ]
    public ?StationMedia $media = null;

    #[
        OA\Property(example: 'Grace Insurance - Fall campaign'),
        ORM\Column(length: 190),
        Assert\NotBlank
    ]
    public string $name = '' {
        set => $this->truncateString($value, 190);
    }

    #[OA\Property(example: 'spots_8'), ORM\Column(length: 40, enumType: AdProducts::class)]
    public AdProducts $product = AdProducts::Spots4;

    #[OA\Property(example: 'approved'), ORM\Column(length: 20, enumType: AdOrderStatus::class)]
    public AdOrderStatus $status = AdOrderStatus::Draft;

    /**
     * Spots a day this order is owed. Set from the product where the product fixes it,
     * otherwise typed in by hand.
     */
    #[OA\Property(example: 8), ORM\Column(type: 'smallint', options: ['default' => 0])]
    public int $spots_per_day = 0 {
        set (int|string|null $value) {
            $spots = (int)$value;
            $this->spots_per_day = max(0, min(96, $spots));
        }
    }

    /** 30 or 60. Sixty-second spots bill at a higher rate on the card. */
    #[OA\Property(example: 30), ORM\Column(type: 'smallint', options: ['default' => 30])]
    public int $spot_seconds = 30 {
        set (int|string|null $value) => 60 === (int)$value ? 60 : 30;
    }

    /** A live read has no audio file: the host reads the script instead. */
    #[OA\Property(example: false), ORM\Column(options: ['default' => false])]
    public bool $is_live_read = false;

    #[OA\Property(example: 'Grace Insurance, protecting what matters.'), ORM\Column(type: 'text', nullable: true)]
    public ?string $script = null;

    /** Which weekly show, for a program sponsorship. */
    #[OA\Property(example: 'Morning Show'), ORM\Column(length: 190, nullable: true)]
    public ?string $program_name = null {
        set => $this->truncateNullableString($value, 190);
    }

    #[OA\Property(example: 1770000000, nullable: true), ORM\Column(nullable: true)]
    public ?int $starts_at = null;

    #[OA\Property(example: 1780000000, nullable: true), ORM\Column(nullable: true)]
    public ?int $ends_at = null;

    #[OA\Property(example: '179.00', nullable: true), ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    public ?string $rate_monthly = null;

    #[OA\Property(example: 'PO-4417'), ORM\Column(length: 80, nullable: true)]
    public ?string $po_number = null {
        set => $this->truncateNullableString($value, 80);
    }

    #[OA\Property(example: 'Renewing in March.'), ORM\Column(type: 'text', nullable: true)]
    public ?string $notes = null;

    #[OA\Property(example: 1770000000), ORM\Column]
    public int $created_at;

    public function __construct(Station $station, AdAdvertiser $advertiser)
    {
        $this->station = $station;
        $this->advertiser = $advertiser;
        $this->created_at = time();
    }

    /** Spots a day this order should actually get: the product's number unless it leaves it open. */
    public function getEffectiveSpotsPerDay(): int
    {
        return $this->product->spotsPerDay() ?? $this->spots_per_day;
    }

    /** True when the order is approved and today falls inside its dates. */
    public function isOnAir(?int $now = null): bool
    {
        if (!$this->status->isPlayable()) {
            return false;
        }

        $now ??= time();

        if (null !== $this->starts_at && $now < $this->starts_at) {
            return false;
        }

        return !(null !== $this->ends_at && $now > $this->ends_at);
    }

    /** An order can only go to air once it has something to play. */
    public function hasCreative(): bool
    {
        return $this->is_live_read
            ? null !== $this->script && '' !== trim($this->script)
            : null !== $this->media;
    }

    public function getStartsAtDateTime(): ?DateTimeImmutable
    {
        return null !== $this->starts_at
            ? (new DateTimeImmutable())->setTimestamp($this->starts_at)
            : null;
    }

    public function getEndsAtDateTime(): ?DateTimeImmutable
    {
        return null !== $this->ends_at
            ? (new DateTimeImmutable())->setTimestamp($this->ends_at)
            : null;
    }

    /** The customer, flattened for lists and forms. */
    public function getAdvertiserId(): int
    {
        return $this->advertiser->id;
    }

    public function getAdvertiserName(): string
    {
        return $this->advertiser->name;
    }

    public function getMediaId(): ?int
    {
        return $this->media?->id;
    }

    /** What the spot file is called, so the order screen can show what is loaded. */
    public function getMediaTitle(): ?string
    {
        if (null === $this->media) {
            return null;
        }

        return $this->media->title ?? $this->media->path;
    }

    public function getPlaylistId(): ?int
    {
        return $this->playlist?->id;
    }

    /** Spots a day after the product has its say; what the delivery engine is told. */
    public function getSpotsPerDayEffective(): int
    {
        return $this->getEffectiveSpotsPerDay();
    }

    public function getIsOnAir(): bool
    {
        return $this->isOnAir();
    }

    public function getHasCreative(): bool
    {
        return $this->hasCreative();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
