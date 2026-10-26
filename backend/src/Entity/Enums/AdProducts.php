<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * What a customer can buy. These mirror the station's printed rate card, so the
 * order screen offers the same list the sales sheet does.
 */
#[OA\Schema(type: 'string')]
enum AdProducts: string
{
    /** "30-second spots, 4 a day" */
    case Spots4 = 'spots_4';

    /** "30-second spots, 8 a day" */
    case Spots8 = 'spots_8';

    /** "Brought to you by" on one weekly show. */
    case ProgramSponsor = 'program_sponsor';

    /** Anything negotiated outside the printed card. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Spots4 => '30-second spots, 4 a day',
            self::Spots8 => '30-second spots, 8 a day',
            self::ProgramSponsor => 'Program sponsorship',
            self::Custom => 'Custom booking',
        };
    }

    /**
     * Spots per day this product includes. Program sponsorship and custom use
     * whatever the order itself says, so they return null.
     */
    public function spotsPerDay(): ?int
    {
        return match ($this) {
            self::Spots4 => 4,
            self::Spots8 => 8,
            self::ProgramSponsor, self::Custom => null,
        };
    }
}
