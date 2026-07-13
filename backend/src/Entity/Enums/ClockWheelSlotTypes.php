<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum ClockWheelSlotTypes: string
{
    /**
     * Copyrighted music — the main content of most radio formats.
     * Liquidsoap will draw from the assigned playlist for this category.
     */
    case Music = 'music';

    /**
     * Talk content: sermons, speeches, interviews, live recordings.
     * Treated separately so talk-heavy formats can be built without mixing with music.
     */
    case Talk = 'talk';

    /**
     * @deprecated Legacy slot value — use {@see Id} at 0:00 for mandatory top-of-hour ID.
     */
    case LegalId = 'legal_id';

    /**
     * Station identification: sweepers, imaging, jingles, and top-of-hour IDs.
     * At 0:00, treated as mandatory (same as legacy LegalId slots).
     */
    case Id = 'id';

    /**
     * Promotional content that is NOT a station ID.
     * Promos are station-produced but longer and content-specific.
     */
    case Promo = 'promo';

    /**
     * Advertisement replacement files.
     * Reserved slot for ad insertion; content comes from the ad playlist.
     */
    case Ad = 'ad';

    public function label(): string
    {
        return match($this) {
            self::Music => 'Music',
            self::Talk  => 'Talk',
            self::LegalId => 'ID',
            self::Id    => 'ID',
            self::Promo => 'Promo',
            self::Ad    => 'Ad',
        };
    }

    /**
     * Mandatory top-of-hour station ID slot (wheel path).
     */
    public static function isMandatoryTopOfHourSlot(?self $type, int $positionSeconds): bool
    {
        if ($type === null) {
            return false;
        }

        return match ($type) {
            self::LegalId => true,
            self::Id => $positionSeconds === 0,
            default => false,
        };
    }
}
