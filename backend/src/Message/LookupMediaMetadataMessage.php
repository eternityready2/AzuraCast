<?php

declare(strict_types=1);

namespace App\Message;

use App\MessageQueue\QueueNames;

final class LookupMediaMetadataMessage extends AbstractUniqueMessage
{
    /** @var int The numeric identifier for the Station the track was uploaded to. */
    public int $station_id;

    /** @var int The numeric identifier for the StationMedia record to look up. */
    public int $media_id;

    public function getIdentifier(): string
    {
        return 'LookupMediaMetadataMessage_' . $this->media_id;
    }

    public function getQueue(): QueueNames
    {
        return QueueNames::Media;
    }
}
