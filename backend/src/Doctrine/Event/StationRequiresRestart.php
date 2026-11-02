<?php

declare(strict_types=1);

namespace App\Doctrine\Event;

use App\Entity\Attributes\AuditIgnore;
use App\Entity\Enums\AuditLogOperations;
use App\Entity\Station;
use App\Entity\StationHlsStream;
use App\Entity\StationMount;
use App\Entity\StationPlaylist;
use App\Entity\Enums\PlaylistSources;
use App\Entity\StationRemote;
use App\Entity\StationSchedule;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use ReflectionObject;

/**
 * A hook into Doctrine's event listener to mark a station as
 * needing restart if one of its related entities is changed.
 *
 * Web streams (and their schedule times) are written into liquidsoap.liq, so
 * saving one marks the station too; the operator restarts when it suits them.
 * (0.31 restarted the station automatically within a minute of every save,
 * mid-show, which knocked stations off the air while a stream was being set up.)
 */
final class StationRequiresRestart implements EventSubscriber
{
    /**
     * @inheritDoc
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
        ];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $collectionsToCheck = [
            [
                AuditLogOperations::Insert,
                $uow->getScheduledEntityInsertions(),
            ],
            [
                AuditLogOperations::Update,
                $uow->getScheduledEntityUpdates(),
            ],
            [
                AuditLogOperations::Delete,
                $uow->getScheduledEntityDeletions(),
            ],
        ];

        $stationsToRestart = [];

        foreach ($collectionsToCheck as [$changeType, $collection]) {
            foreach ($collection as $entity) {
                $webStream = match (true) {
                    $entity instanceof StationPlaylist => $entity,
                    $entity instanceof StationSchedule => $entity->playlist,
                    default => null,
                };
                if (
                    null !== $webStream
                    && PlaylistSources::RemoteUrl === $webStream->source
                    && $webStream->station->hasLocalServices()
                    && !$this->isOnlyIgnoredChanges($uow, $changeType, $entity)
                ) {
                    $stationsToRestart[$webStream->station->id] = $webStream->station;
                    continue;
                }

                if (
                    ($entity instanceof StationMount)
                    || ($entity instanceof StationHlsStream)
                    || ($entity instanceof StationRemote && $entity->isEditable())
                    || ($entity instanceof StationPlaylist && $entity->station->backend_config->use_manual_autodj)
                ) {
                    if ($this->isOnlyIgnoredChanges($uow, $changeType, $entity)) {
                        continue;
                    }

                    $station = $entity->station;
                    if ($station->hasLocalServices()) {
                        $stationsToRestart[$station->id] = $station;
                    }
                }
            }
        }

        if (count($stationsToRestart) > 0) {
            foreach ($stationsToRestart as $station) {
                $station->needs_restart = true;
                $em->persist($station);

                $stationMeta = $em->getClassMetadata(Station::class);
                $uow->recomputeSingleEntityChangeSet($stationMeta, $station);
            }
        }
    }

    private function isOnlyIgnoredChanges(
        UnitOfWork $uow,
        AuditLogOperations $changeType,
        object $entity
    ): bool {
        if (AuditLogOperations::Update !== $changeType) {
            return false;
        }

        $changes = $uow->getEntityChangeSet($entity);

        // Look for the @AuditIgnore annotation on a property.
        $classReflection = new ReflectionObject($entity);
        foreach ($changes as $changeField => $changeset) {
            $ignoreAttr = $classReflection->getProperty($changeField)->getAttributes(
                AuditIgnore::class
            );
            if (!empty($ignoreAttr)) {
                unset($changes[$changeField]);
            }
        }

        return empty($changes);
    }
}
