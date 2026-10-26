<?php

declare(strict_types=1);

namespace App\Doctrine\Event;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationSchedule;
use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLogPreviewContext;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

/**
 * Re-plans a station's saved linear log when its schedule changes.
 *
 * The log is planned up to two days ahead. A schedule row or clock wheel
 * added, moved or removed after that leaves the planned hours describing the
 * old schedule: the log rules then drop those lines at air time and the hour
 * plays live with no log behind it. Lines an operator locked are kept by the
 * rebuild itself.
 */
final class LinearLogReplanOnScheduleChange implements EventSubscriber
{
    /** @var array<int, int> station id => log hours */
    private array $pending = [];

    public function __construct(
        private readonly ContainerInterface $di,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
            Events::postFlush,
        ];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $changed = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];

        foreach ($changed as $entity) {
            $station = match (true) {
                $entity instanceof StationSchedule => $entity->playlist->station
                    ?? $entity->clock_wheel->station
                    ?? $entity->streamer?->station,
                $entity instanceof StationClockWheel => $entity->station,
                $entity instanceof StationClockWheelSlot => $entity->clock_wheel->station,
                default => null,
            };

            if ($station instanceof Station && LinearLogPlayout::isPlayoutEnabled($station)) {
                $this->pending[$station->id] = $station->backend_config->linear_log_hours;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        // The build's own simulation writes inside a transaction it rolls back.
        if ($this->di->get(LinearLogPreviewContext::class)->isActive()) {
            return;
        }

        foreach ($pending as $stationId => $hours) {
            try {
                $this->di->get(MessageBus::class)->dispatch(
                    new BuildLinearLogMessage($stationId, $hours, false, true, false, true)
                );
            } catch (Throwable) {
                // The hourly build still picks the change up.
            }
        }
    }
}
