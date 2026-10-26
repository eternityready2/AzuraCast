<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Enums\RecurrenceType;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use InvalidArgumentException;

/**
 * Station setup that makes the 24-hour log misbehave, found in advance and
 * told in plain words, with a one-click fix where the fix is safe to make.
 *
 * The log itself is not changed here: this only reads the station's schedules,
 * playlists and media, and each fix changes exactly one setting the operator
 * can see and change back. Everything found on the night of 2026-10-25:
 * a show starting at 12:03 after the midnight ID (silence, songs thrown out),
 * a show ending at 11:59 (the same promo three times before the ID), show
 * episodes in a normal playlist (the 9 AM hour dropped by the ID), schedules
 * with no repeat setting, duplicate schedules, show playlists with no files,
 * a show and an ad tagged as Station IDs.
 */
final class LinearLogSetupCheck
{
    use EntityManagerAwareTrait;

    /** A file this long is a show, not a song (as the rest of the log counts one). */
    private const float SHOW_MIN_SECONDS = 1200.0;

    /** Minutes after/before the hour that leave a hole next to the station ID. */
    private const int EDGE_MINUTES = 5;

    public function __construct(
        private readonly TopOfHourClock $topOfHourClock,
    ) {
    }

    /**
     * @return list<array{id: string, message: string, fix: string|null, link: string|null}>
     */
    public function check(Station $station): array
    {
        $station = $this->em->refetch($station);
        $issues = [];
        $idOn = $this->topOfHourClock->isEnabled($station);

        $playlists = $this->em->getRepository(StationPlaylist::class)->findBy(['station' => $station]);
        $schedulesByPlaylist = [];

        foreach ($playlists as $playlist) {
            if (!$playlist->is_enabled || $playlist->is_smart_block) {
                continue;
            }

            /** @var StationSchedule[] $schedules */
            $schedules = $playlist->schedule_items->toArray();
            $schedulesByPlaylist[$playlist->id] = $schedules;
            $link = sprintf('/station/%d/playlist/%d?tab=schedule', $station->id, $playlist->id);

            $seen = [];
            foreach ($schedules as $schedule) {
                $key = implode('|', [
                    $schedule->start_time,
                    $schedule->end_time,
                    implode(',', $schedule->days),
                    $schedule->start_date,
                    $schedule->end_date,
                ]);
                if (isset($seen[$key])) {
                    $issues[] = [
                        'id' => 'duplicate:' . $schedule->id,
                        'message' => sprintf(
                            __('%s has the same scheduled time listed twice (%s).'),
                            $playlist->name,
                            $this->timeRange($schedule)
                        ),
                        'fix' => __('Remove the extra copy'),
                        'link' => $link,
                    ];
                    continue;
                }
                $seen[$key] = true;

                if (null === $schedule->recurrence_type && [] !== $schedule->days) {
                    $issues[] = [
                        'id' => 'repeat:' . $schedule->id,
                        'message' => sprintf(
                            __('%s (%s) has no repeat setting saved, so some pages show it as a one-time event.'),
                            $playlist->name,
                            $this->timeRange($schedule)
                        ),
                        'fix' => __('Set it to repeat weekly'),
                        'link' => $link,
                    ];
                }

                if (!$idOn) {
                    continue;
                }

                $startMinute = $schedule->start_time % 100;
                if ($startMinute >= 1 && $startMinute <= self::EDGE_MINUTES) {
                    $issues[] = [
                        'id' => 'start:' . $schedule->id,
                        'message' => sprintf(
                            __('%s starts at %s, so the %d minute(s) after the station ID have nothing allowed to play.'),
                            $playlist->name,
                            $this->clock($schedule->start_time),
                            $startMinute
                        ),
                        'fix' => sprintf(__('Start it at %s'), $this->clock($schedule->start_time - $startMinute)),
                        'link' => $link,
                    ];
                }

                $endMinute = $schedule->end_time % 100;
                if ($endMinute >= 60 - self::EDGE_MINUTES && $endMinute <= 59) {
                    $issues[] = [
                        'id' => 'end:' . $schedule->id,
                        'message' => sprintf(
                            __('%s ends at %s, leaving %d minute(s) before the station ID that nothing fills well.'),
                            $playlist->name,
                            $this->clock($schedule->end_time),
                            60 - $endMinute
                        ),
                        'fix' => sprintf(__('End it at %s'), $this->clock($this->nextHour($schedule->end_time))),
                        'link' => $link,
                    ];
                }
            }

            if (
                [] === $schedules
                || PlaylistSources::Songs !== $playlist->source
                || $playlist->is_jingle
            ) {
                continue;
            }

            $lengths = [];
            foreach ($playlist->media_items as $item) {
                $lengths[] = $item->media->getCalculatedLength();
            }

            if ([] === $lengths) {
                $issues[] = [
                    'id' => 'empty:' . $playlist->id,
                    'message' => sprintf(
                        __('%s is scheduled but has no audio files, so nothing can play in its time slot.'),
                        $playlist->name
                    ),
                    'fix' => null,
                    'link' => sprintf('/station/%d/files', $station->id),
                ];
                continue;
            }

            if (!$playlist->is_programme && min($lengths) >= self::SHOW_MIN_SECONDS) {
                $issues[] = [
                    'id' => 'show:' . $playlist->id,
                    'message' => sprintf(
                        __('%s plays shows (each file is 20 minutes or longer) but isn\'t marked as a show, so the station ID can drop it.'),
                        $playlist->name
                    ),
                    'fix' => __('Mark it as a show'),
                    'link' => $link,
                ];
            }
        }

        // Rotation: what plays when no show is scheduled.
        $hasRotation = false;
        foreach ($playlists as $playlist) {
            if (
                $playlist->is_enabled
                && !$playlist->is_jingle
                && PlaylistTypes::Standard === $playlist->type
                && PlaylistSources::Songs === $playlist->source
                && [] === ($schedulesByPlaylist[$playlist->id] ?? [])
                && $playlist->media_items->count() > 0
            ) {
                $hasRotation = true;
                break;
            }
        }
        if (!$hasRotation) {
            $issues[] = [
                'id' => 'rotation',
                'message' => __('Nothing is set to play when no show is scheduled. Add songs to a playlist that has no schedule.'),
                'fix' => null,
                'link' => sprintf('/station/%d/playlists', $station->id),
            ];
        }

        // Station ID files.
        /** @var StationMedia[] $idFiles */
        $idFiles = $this->em->createQuery(
            <<<'DQL'
                SELECT sm FROM App\Entity\StationMedia sm
                WHERE IDENTITY(sm.storage_location) = :storage AND sm.type IN (:types)
            DQL
        )->setParameter('storage', $station->media_storage_location->id)
            ->setParameter('types', StationMediaTypes::stationIdTypeValues())
            ->getResult();
        $idMax = $this->topOfHourClock->getIdMaxSeconds($station);
        $fittingIds = 0;
        foreach ($idFiles as $media) {
            $length = $media->getCalculatedLength();
            if ($length <= $idMax) {
                $fittingIds++;
                continue;
            }

            $issues[] = [
                'id' => 'idtag:' . $media->id,
                'message' => sprintf(
                    __('"%s" is %s long but is marked as a Station ID.'),
                    $media->title ?? basename($media->path),
                    $this->duration($length)
                ),
                'fix' => $length >= self::SHOW_MIN_SECONDS ? __('Mark it as talk') : null,
                'link' => sprintf('/station/%d/files', $station->id),
            ];
        }
        if ($idOn && 0 === $fittingIds) {
            $issues[] = [
                'id' => 'noid',
                'message' => sprintf(
                    __('The Top of Hour ID is on, but no audio file under %d seconds is marked as a Station ID.'),
                    $idMax
                ),
                'fix' => null,
                'link' => sprintf('/station/%d/files', $station->id),
            ];
        }

        return $issues;
    }

    /**
     * Makes the one change the issue's fix describes.
     *
     * @return bool whether a setting changed
     */
    public function fix(Station $station, string $issueId): bool
    {
        $station = $this->em->refetch($station);
        $fixable = array_column(
            array_filter($this->check($station), static fn(array $issue): bool => null !== $issue['fix']),
            'id'
        );
        if (!in_array($issueId, $fixable, true)) {
            throw new InvalidArgumentException('This problem is already fixed, or has no automatic fix.');
        }

        $this->save($station, [$this->apply($station, $issueId)]);

        return true;
    }

    /**
     * Every fixable problem, saved together: one save, so the log replans
     * once rather than once per fix.
     *
     * @return int how many problems were fixed
     */
    public function fixAll(Station $station): int
    {
        $station = $this->em->refetch($station);

        $kinds = [];
        $removed = [];
        foreach ($this->check($station) as $issue) {
            if (null === $issue['fix'] || isset($removed[$issue['id']])) {
                continue;
            }
            $kinds[] = $this->apply($station, $issue['id']);
            if (str_starts_with($issue['id'], 'duplicate:')) {
                // A removed copy's own problems go with it.
                $id = substr($issue['id'], strlen('duplicate:'));
                foreach (['repeat', 'start', 'end'] as $kind) {
                    $removed[$kind . ':' . $id] = true;
                }
            }
        }

        if ([] !== $kinds) {
            $this->save($station, $kinds);
        }

        return count($kinds);
    }

    /** Stages one fix (no save); returns its kind. */
    private function apply(Station $station, string $issueId): string
    {
        [$kind, $id] = array_pad(explode(':', $issueId, 2), 2, null);
        $id = (int)$id;

        switch ($kind) {
            case 'duplicate':
                $this->em->remove($this->scheduleOf($station, $id));
                break;

            case 'repeat':
                $schedule = $this->scheduleOf($station, $id);
                $schedule->recurrence_type = RecurrenceType::Weekly;
                $this->em->persist($schedule);
                break;

            case 'start':
                $schedule = $this->scheduleOf($station, $id);
                $schedule->start_time -= $schedule->start_time % 100;
                $this->em->persist($schedule);
                break;

            case 'end':
                $schedule = $this->scheduleOf($station, $id);
                $schedule->end_time = $this->nextHour($schedule->end_time);
                $this->em->persist($schedule);
                break;

            case 'show':
                $playlist = $this->em->find(StationPlaylist::class, $id);
                if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
                    throw new InvalidArgumentException('Playlist not found.');
                }
                $playlist->is_programme = true;
                $this->em->persist($playlist);
                break;

            case 'idtag':
                $media = $this->em->find(StationMedia::class, $id);
                if (
                    !$media instanceof StationMedia
                    || $media->storage_location->id !== $station->media_storage_location->id
                ) {
                    throw new InvalidArgumentException('File not found.');
                }
                $media->type = 'talk';
                $this->em->persist($media);
                break;

            default:
                throw new InvalidArgumentException('Unknown problem.');
        }

        return (string)$kind;
    }

    /** @param list<string> $kinds */
    private function save(Station $station, array $kinds): void
    {
        $this->em->flush();

        // Schedules and shows are written into the station's playout config:
        // the station shows its usual "restart to apply" notice.
        if ([] !== array_diff($kinds, ['idtag'])) {
            $this->em->getConnection()->executeStatement(
                'UPDATE station SET needs_restart = 1 WHERE id = ?',
                [$station->id]
            );
        }
    }

    private function scheduleOf(Station $station, int $id): StationSchedule
    {
        $schedule = $this->em->find(StationSchedule::class, $id);
        if (!$schedule instanceof StationSchedule || $schedule->playlist?->station->id !== $station->id) {
            throw new InvalidArgumentException('Schedule not found.');
        }

        return $schedule;
    }

    private function nextHour(int $hhmm): int
    {
        $hour = intdiv($hhmm, 100) + 1;

        return 24 === $hour ? 0 : $hour * 100;
    }

    private function clock(int $hhmm): string
    {
        $hour = intdiv($hhmm, 100) % 24;
        $minute = $hhmm % 100;

        return sprintf('%d:%02d %s', 0 === $hour % 12 ? 12 : $hour % 12, $minute, $hour < 12 ? 'AM' : 'PM');
    }

    private function timeRange(StationSchedule $schedule): string
    {
        return $this->clock($schedule->start_time) . '–' . $this->clock($schedule->end_time);
    }

    private function duration(float $seconds): string
    {
        $minutes = (int)round($seconds / 60);

        return $minutes >= 60
            ? sprintf(__('%d h %02d min'), intdiv($minutes, 60), $minutes % 60)
            : sprintf(__('%d min'), max(1, $minutes));
    }
}
