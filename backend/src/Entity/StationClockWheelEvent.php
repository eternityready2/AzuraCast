<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Attributes\Auditable;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A scheduled occurrence of a Clock Wheel within a station's programming calendar.
 *
 * Each event links a Clock Wheel to one or more weekdays and a time range (stored
 * as AzuraCast time-codes: 900 = 09:00, 1700 = 17:00).  Events repeat weekly on
 * the selected days unless `days` is NULL, in which case the event runs every day.
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_clock_wheel_events'),
    Auditable
]
final class StationClockWheelEvent implements IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;

    // ------------------------------------------------------------------
    // Clock Wheel relationship
    // ------------------------------------------------------------------

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'clock_wheel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public StationClockWheel $clock_wheel;

    /** Raw FK — read-only, used by API serialisation. */
    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $clock_wheel_id;

    // ------------------------------------------------------------------
    // Time range (AzuraCast time-code format: HHMM as int)
    // ------------------------------------------------------------------

    #[
        OA\Property(example: 900, description: 'Start time in AzuraCast format (900 = 09:00)'),
        ORM\Column(type: 'smallint'),
        Assert\Range(min: 0, max: 2359)
    ]
    public int $start_time = 0;

    #[
        OA\Property(example: 1700, description: 'End time in AzuraCast format (1700 = 17:00)'),
        ORM\Column(type: 'smallint'),
        Assert\Range(min: 0, max: 2359)
    ]
    public int $end_time = 0;

    // ------------------------------------------------------------------
    // Weekday selection (ISO 8601: 1=Mon … 7=Sun, comma-separated)
    // ------------------------------------------------------------------

    #[
        OA\Property(
            example: '1,2,3,4,5',
            description: 'Comma-separated ISO weekday numbers (1=Mon … 7=Sun). NULL means every day.'
        ),
        ORM\Column(length: 50, nullable: true)
    ]
    public ?string $days = null;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Returns the days array, or all days (1-7) when `days` is not set. */
    public function getDaysArray(): array
    {
        if (null === $this->days || '' === $this->days) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        return array_map('intval', explode(',', $this->days));
    }

    public function __construct(StationClockWheel $clockWheel)
    {
        $this->clock_wheel = $clockWheel;
    }
}
