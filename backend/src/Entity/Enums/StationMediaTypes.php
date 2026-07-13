<?php

declare(strict_types=1);

namespace App\Entity\Enums;

/**
 * Station media type helpers. {@see ClockWheelSlotTypes::Id} is the single UI type for
 * sweepers and top-of-hour IDs; {@see self::LEGACY_LEGAL_ID} is accepted for existing rows only.
 */
final class StationMediaTypes
{
    public const string ID = 'id';

    /** @deprecated Merged into {@see ID} — still read from DB for legacy files. */
    public const string LEGACY_LEGAL_ID = 'legal_id';

    /**
     * @return non-empty-list<string>
     */
    public static function stationIdTypeValues(): array
    {
        return [self::ID, self::LEGACY_LEGAL_ID];
    }

    public static function isStationId(?string $type): bool
    {
        return in_array($type, self::stationIdTypeValues(), true);
    }
}
