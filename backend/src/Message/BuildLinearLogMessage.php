<?php

declare(strict_types=1);

namespace App\Message;

use App\MessageQueue\QueueNames;

final class BuildLinearLogMessage extends AbstractUniqueMessage
{
    public bool $force = false;

    public function __construct(
        public readonly int $stationId,
        public readonly int $hours,
        bool $force = false,
        // Saved linear log: re-plan the unlocked hours instead of only extending.
        public readonly bool $rebuild = false,
        // Daily FM-style build: plan through the end of tomorrow (max 48h), so
        // tomorrow's log is ready before today's ends.
        public readonly bool $throughTomorrow = false,
    ) {
        $this->force = $force;
    }

    public function getIdentifier(): string
    {
        return 'BuildLinearLogMessage_station_' . $this->stationId;
    }

    public function getTtl(): float
    {
        return 3600;
    }

    public function getQueue(): QueueNames
    {
        return QueueNames::LowPriority;
    }
}
