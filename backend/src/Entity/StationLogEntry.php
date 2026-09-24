<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a station's saved 24-hour linear log.
 *
 * The log is the plan AutoDJ plays in order when "log controls playout" is on:
 * the builder extends it every hour and never reshuffles rows it already
 * planned; playout turns the next planned row into a queue row; air-time
 * decisions (DMCA replacement, Top-of-Hour swap, drops at an hour post) are
 * written back here so the log always matches what aired.
 */
#[
    ORM\Entity,
    ORM\Table(name: 'station_log_entries'),
    ORM\Index(name: 'idx_station_log_planned', columns: ['station_id', 'planned_at']),
    ORM\Index(name: 'idx_station_log_status', columns: ['station_id', 'status'])
]
class StationLogEntry
{
    public const string STATUS_PLANNED = 'planned';
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_AIRED = 'aired';
    public const string STATUS_SWAPPED = 'swapped';
    public const string STATUS_REPLACED = 'replaced';
    public const string STATUS_DROPPED = 'dropped';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) int $id;

    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Station $station;

    /** Planned start (unix seconds). Re-timed on every build; order is kept by $sequence. */
    #[ORM\Column]
    public int $planned_at;

    /** Stable play order. Never changed by rebuilds, only by operator moves. */
    #[ORM\Column]
    public int $sequence;

    #[ORM\Column]
    public float $duration = 0.0;

    #[ORM\ManyToOne(targetEntity: StationMedia::class)]
    #[ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?StationMedia $media = null;

    #[ORM\ManyToOne(targetEntity: StationPlaylist::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?StationPlaylist $playlist = null;

    #[ORM\Column(length: 20)]
    public string $status = self::STATUS_PLANNED;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $text = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $artist = null;

    /** Queue-row settings needed to play this line exactly as planned (clock wheel caps etc.). */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $payload = null;

    /** The queue row this line became when playout took it. */
    #[ORM\Column(nullable: true)]
    public ?int $queue_id = null;

    /** Actual on-air start (unix seconds). */
    #[ORM\Column(nullable: true)]
    public ?int $aired_at = null;

    /** Why this line changed, e.g. "Swapped at the top of the hour; planned: X". */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $note = null;

    /** Operator lock: rebuilds never replace or drop a locked line. */
    #[ORM\Column]
    public bool $is_locked = false;

    #[ORM\Column]
    public int $created_at;

    public function __construct(Station $station, int $plannedAt, int $sequence)
    {
        $this->station = $station;
        $this->planned_at = $plannedAt;
        $this->sequence = $sequence;
        $this->created_at = time();
    }

    public function isOpen(): bool
    {
        return self::STATUS_PLANNED === $this->status || self::STATUS_QUEUED === $this->status;
    }
}
