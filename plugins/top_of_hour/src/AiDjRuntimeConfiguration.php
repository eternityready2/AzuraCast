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
 * Strict programme. It is gated off whenever a human live streamer is active,
 * so automated speech never talks over a live presenter. TopOfHourRuntimeConfiguration
 * is registered immediately after this subscriber at the same priority, so TOH
 * remains the final authority over the complete station graph.
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

            # A human presenter always wins over automated speech. source.available
            # keeps a queued clip parked while live is active; the PHP runtime also
            # stops generating new clips during live sessions, so a presenter cannot
            # be interrupted by a welcome, liner or sign-off generated mid-show.
            ai_dj_available = source.available(
                ai_dj_queue,
                predicate.activates({ not azuracast.live_enabled() })
            )

            radio = fallback(
                id="ai_dj_runtime",
                track_sensitive=true,
                transition_length=0.0,
                [ai_dj_available, radio]
            )
            LIQ
        );
    }
}
