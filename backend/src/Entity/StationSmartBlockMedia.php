<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A smart block's own memory of a song: when it last played from this smart block
 * (so it rotates through its matches), and for a Static smart block, the frozen
 * list of songs it plays (with their order).
 */
#[
    ORM\Entity,
    ORM\Table(name: 'station_smart_block_media'),
    ORM\UniqueConstraint(name: 'smart_block_media_unique', columns: ['smart_block_id', 'media_id'])
]
final class StationSmartBlockMedia implements IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;

    #[ORM\ManyToOne(inversedBy: 'media_items')]
    #[ORM\JoinColumn(name: 'smart_block_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationSmartBlock $smart_block;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationMedia $media;

    /** Static smart blocks: this song is part of the frozen list. */
    #[ORM\Column(options: ['default' => false])]
    public bool $is_member = false;

    /** Position in a Static smart block's list. */
    #[ORM\Column(options: ['default' => 0])]
    public int $weight = 0;

    /** Unix time this song last aired from this smart block; 0 = never. */
    #[ORM\Column(options: ['default' => 0])]
    public int $last_played = 0;

    public function __construct(StationSmartBlock $smartBlock, StationMedia $media)
    {
        $this->smart_block = $smartBlock;
        $this->media = $media;
    }
}
