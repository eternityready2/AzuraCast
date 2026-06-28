<?php

declare(strict_types=1);

namespace App\Entity;

use Azura\Normalizer\Attributes\DeepNormalize;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Attribute as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

#[
    OA\Schema(
        description: 'Reusable AI DJ content and templates for station-specific or global use.',
        type: 'object'
    ),
    ORM\Entity,
    ORM\Table(name: 'ai_dj_content'),
    ORM\Index(name: 'idx_ai_dj_content_station', columns: ['station_id']),
    ORM\Index(name: 'idx_ai_dj_content_type', columns: ['type']),
    Attributes\Auditable
]
final class AiDjContent implements Interfaces\IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    public const string TYPE_SONG_INTRO_TEMPLATE = 'song_intro_template';
    public const string TYPE_POST_SONG_TEMPLATE = 'post_song_template';
    public const string TYPE_BIBLE_VERSE = 'bible_verse';
    public const string TYPE_JOKE = 'joke';
    public const string TYPE_ENCOURAGEMENT = 'encouragement';
    public const string TYPE_INSPIRATION = 'inspiration';
    public const string TYPE_TESTIMONY = 'testimony';
    public const string TYPE_STORY = 'story';

    public const array TYPES = [
        self::TYPE_SONG_INTRO_TEMPLATE,
        self::TYPE_POST_SONG_TEMPLATE,
        self::TYPE_BIBLE_VERSE,
        self::TYPE_JOKE,
        self::TYPE_ENCOURAGEMENT,
        self::TYPE_INSPIRATION,
        self::TYPE_TESTIMONY,
        self::TYPE_STORY,
    ];

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        OA\Property(example: self::TYPE_SONG_INTRO_TEMPLATE),
        ORM\Column(length: 50),
        Assert\NotBlank,
        Assert\Regex(pattern: '/^[a-z][a-z0-9_]{1,49}$/', message: 'Type must be lowercase letters, numbers, and underscores (2-50 chars).')
    ]
    public string $type = self::TYPE_SONG_INTRO_TEMPLATE {
        set => $this->truncateString($value, 50);
    }

    #[
        OA\Property(example: 'Coming up next: {{artist}} with {{song}}.'),
        ORM\Column(type: 'text'),
        Assert\NotBlank
    ]
    public string $content = '';

    #[
        OA\Property(example: 'John 3:16'),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $reference = null {
        set => $this->truncateNullableString($value);
    }

    #[
        OA\Property(example: true),
        ORM\Column
    ]
    public bool $is_enabled = true;

    #[
        OA\Property(example: false),
        ORM\Column
    ]
    public bool $is_global = false;

    /** @var Collection<int, AiDj> */
    #[
        OA\Property(type: 'array', items: new OA\Items()),
        ORM\ManyToMany(targetEntity: AiDj::class, mappedBy: 'contents'),
        DeepNormalize(true),
        Serializer\MaxDepth(1)
    ]
    public private(set) Collection $ai_djs;

    public function __construct(Station $station)
    {
        $this->station = $station;
        $this->ai_djs = new ArrayCollection();
    }

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }
}
