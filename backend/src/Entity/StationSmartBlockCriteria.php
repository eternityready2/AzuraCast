<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use OpenApi\Attributes as OA;

/**
 * One rule of a {@see StationSmartBlock}. A smart block's rules, combined by its
 * match type (ALL/ANY), decide which songs from the media library it plays.
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_smart_block_criteria'),
    Attributes\Auditable
]
final class StationSmartBlockCriteria implements JsonSerializable, IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'criteria')]
    #[ORM\JoinColumn(name: 'smart_block_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationSmartBlock $smart_block;

    #[
        OA\Property(example: 'genre'),
        ORM\Column(type: 'string', length: 25, enumType: SmartBlockCriteriaField::class)
    ]
    public SmartBlockCriteriaField $field = SmartBlockCriteriaField::Genre;

    #[ORM\ManyToOne(fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    public ?CustomField $custom_field = null;

    #[
        OA\Property(example: 'is'),
        ORM\Column(type: 'string', length: 25, enumType: SmartBlockCriteriaComparison::class)
    ]
    public SmartBlockCriteriaComparison $comparison = SmartBlockCriteriaComparison::Is;

    #[
        OA\Property(example: 'Rock'),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $value = null {
        set => $this->truncateNullableString($value);
    }

    #[
        OA\Property(example: '140'),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $value2 = null {
        set => $this->truncateNullableString($value);
    }

    #[
        OA\Property(example: 0),
        ORM\Column
    ]
    public int $weight = 0;

    public function __construct(StationSmartBlock $smartBlock)
    {
        $this->smart_block = $smartBlock;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field->value,
            'custom_field_id' => $this->custom_field?->id,
            'custom_field_name' => $this->custom_field?->name,
            'comparison' => $this->comparison->value,
            'value' => $this->value,
            'value2' => $this->value2,
            'weight' => $this->weight,
        ];
    }
}
