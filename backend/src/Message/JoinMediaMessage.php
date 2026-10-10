<?php

declare(strict_types=1);

namespace App\Message;

use App\MessageQueue\QueueNames;

final class JoinMediaMessage extends AbstractMessage
{
    /** @var string The identifier the Music Files page asks about while the join runs. */
    public string $job_id;

    /** @var int The numeric identifier for the StorageLocation entity. */
    public int $storage_location_id;

    /** @var int[] The StationMedia records to join, in the order they are joined. */
    public array $media_ids = [];

    /** @var string The title of the joined file, as typed. */
    public string $name;

    /** @var bool Whether the joined file takes the place of its parts in their playlists. */
    public bool $replace_in_playlists = false;

    public function getQueue(): QueueNames
    {
        return QueueNames::Media;
    }
}
