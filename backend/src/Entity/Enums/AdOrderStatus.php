<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum AdOrderStatus: string
{
    /** Being written up. Nothing airs. */
    case Draft = 'draft';

    /** Sold and accepted. Airs inside its dates. */
    case Approved = 'approved';

    /** Temporarily off the air; the booking still exists. */
    case Paused = 'paused';

    /** Ran its term. Kept for the record and for invoicing. */
    case Ended = 'ended';

    /** Turned down (content, or the advertiser walked). Never airs. */
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Paused => 'Paused',
            self::Ended => 'Ended',
            self::Declined => 'Declined',
        };
    }

    /** Whether an order in this state should be on the air at all. */
    public function isPlayable(): bool
    {
        return self::Approved === $this;
    }
}
