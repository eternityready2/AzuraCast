<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Enums\LiquidsoapQueues;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives AI DJ speech its own track-sensitive lane above Strict scheduled
 * programming while remaining below the exact Top-of-Hour ID lane.
 *
 * AI DJ speech used to share the listener Requests queue. A Strict native
 * programme owns the graph above that queue, so a midnight welcome could sit
 * unheard until the Strict block ended hours later. While it sat there, the
 * occupied Requests queue also prevented every later DJ talk opportunity.
 *
 * The dedicated lane keeps listener requests separate and lets welcomes,
 * regular breaks and sign-offs air at the next safe track boundary inside a
 * Strict programme. TopOfHourRuntimeConfiguration is registered immediately
 * after this subscriber at the same priority, so the final authority order is:
 *
 *     Top-of-Hour ID -> AI DJ -> Strict programme -> live/AutoDJ
 */
final class AiDjRuntimeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 15],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $queueName = LiquidsoapQueues::AiDj->value;

        $event->appendBlock(
            <<<LIQ
            # Dedicated AI DJ speech lane.
            ai_dj_queue = request.queue(
                id="{$queueName}",
                timeout=settings.azuracast.request_timeout()
            )

            radio = fallback(
                id="ai_dj_runtime",
                track_sensitive=true,
                transition_length=0.0,
                [ai_dj_queue, radio]
            )
            LIQ
        );
    }
}
