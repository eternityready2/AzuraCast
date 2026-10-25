<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One hand edit of a linear log line: what was done, when, and what the line
 * was before. The log line itself only keeps its latest state, so this is what
 * an operator reads back afterwards and what Undo restores from.
 */
#[
    ORM\Entity,
    ORM\Table(name: 'station_log_edits'),
    ORM\Index(name: 'idx_station_log_edits_entry', columns: ['entry_id', 'id'])
]
class StationLogEdit
{
    public const string EDIT_LOCK = 'lock';
    public const string EDIT_UNLOCK = 'unlock';
    public const string EDIT_UP = 'up';
    public const string EDIT_DOWN = 'down';
    public const string EDIT_REMOVE = 'remove';
    public const string EDIT_REPLACE = 'replace';
    public const string EDIT_UNDO = 'undo';

    /** Edits Undo puts back: the two that change what airs. */
    public const array UNDOABLE = [self::EDIT_REMOVE, self::EDIT_REPLACE];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) int $id;

    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Station $station;

    /** History goes when its line is pruned from the log. */
    #[ORM\ManyToOne(targetEntity: StationLogEntry::class)]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly StationLogEntry $entry;

    #[ORM\Column(length: 20)]
    public readonly string $edit;

    /** Unix seconds. */
    #[ORM\Column]
    public readonly int $edited_at;

    /**
     * The line (and its queue slot, if it had one) as it was before the edit.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    public readonly array $before_state;

    /** Unix seconds; set once Undo has put the line back. */
    #[ORM\Column(nullable: true)]
    public ?int $undone_at = null;

    /** @param array<string, mixed> $beforeState */
    public function __construct(StationLogEntry $entry, string $edit, array $beforeState)
    {
        $this->station = $entry->station;
        $this->entry = $entry;
        $this->edit = $edit;
        $this->before_state = $beforeState;
        $this->edited_at = time();
    }
}
