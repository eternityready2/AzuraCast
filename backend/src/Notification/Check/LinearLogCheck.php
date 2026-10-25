<?php

declare(strict_types=1);

namespace App\Notification\Check;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Api\Notification;
use App\Entity\Station;
use App\Enums\FlashLevels;
use App\Enums\StationPermissions;
use App\Event\GetNotifications;
use App\Radio\AutoDJ\LinearLog\LinearLogAlerts;
use App\Radio\AutoDJ\LinearLog\LinearLogStore;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use Throwable;

/**
 * Linear Log alerts on the dashboard, so a hole or a short hour is seen
 * without the log page being open: the same alerts the page itself shows.
 */
final class LinearLogCheck
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogStore $store,
        private readonly LinearLogAlerts $alerts,
    ) {
    }

    public function __invoke(GetNotifications $event): void
    {
        $request = $event->getRequest();
        $acl = $request->getAcl();
        $router = $request->getRouter();

        /** @var Station[] $stations */
        $stations = $this->em->getRepository(Station::class)->findBy(['is_enabled' => true]);

        foreach ($stations as $station) {
            if (
                !$station->backend_config->linear_log_enabled
                || !$acl->isAllowed(StationPermissions::Reports, $station->id)
            ) {
                continue;
            }

            try {
                $snapshot = $this->snapshotStore->get($station);
                if (empty($snapshot['entries'])) {
                    continue;
                }

                $hours = $station->backend_config->linear_log_hours;
                $entries = $this->store->liveEntries($station, $snapshot['entries'], $hours);
                $alerts = $this->alerts->forStation($station, $this->store->measureCoverage($station, $entries, $hours));
            } catch (Throwable) {
                // The log page reports its own failures; the dashboard stays up.
                continue;
            }

            foreach ($alerts as $alert) {
                $event->addNotification(
                    new Notification(
                        id: sprintf('notification-linear-log-%d-%s-%d', $station->id, $alert['type'], $alert['at']),
                        title: sprintf(__('%s: Linear Log'), $station->name),
                        body: $alert['message'],
                        type: 'danger' === $alert['level'] ? FlashLevels::Error : FlashLevels::Warning,
                        actionLabel: __('Linear Log'),
                        // The page has no named route of its own; it sits under the station's.
                        actionUrl: $router->named('stations:index:index', ['station_id' => $station->id])
                            . '/reports/linear-log',
                    )
                );
            }
        }
    }
}
