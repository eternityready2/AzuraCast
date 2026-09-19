<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Enums\LiquidsoapQueues;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AiDjRuntimeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [WriteLiquidsoapConfiguration::class => ['writeRuntime', 15]];
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
                [ai_dj_queue, radio]
            )
            LIQ
        );
    }
}