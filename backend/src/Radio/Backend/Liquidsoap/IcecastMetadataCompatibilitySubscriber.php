<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Liquidsoap 2.4 / Icecast 2.5 compatibility for metadata handling.
 *
 * Icecast 2.5 warns when an ICY metadata update submits its synthesized `song`
 * field together with `artist` and/or `title`. Liquidsoap's default Icecast
 * metadata list includes all three, so every normal song change can generate a
 * warning. Limit ICY updates to the listener-compatible `song` value; Liquidsoap
 * still synthesizes it from artist/title using its standard icy_song function.
 *
 * Some MP3 files also carry very large PRIV/XMP frames. They are binary/private
 * metadata and should not be passed through Liquidsoap's UTF-8 recoder.
 */
final class IcecastMetadataCompatibilitySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['writeMetadataDecoderCompatibility', 34],
                ['normalizeIcecastMetadata', -4],
            ],
        ];
    }

    public function writeMetadataDecoderCompatibility(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendLines([
            '# Ignore large private/binary MP3 metadata during UTF-8 recoding.',
            'settings.request.metadata_decoders.recode.exclude := ["priv", "id3v2_priv.xmp"]',
        ]);
    }

    public function normalizeIcecastMetadata(WriteLiquidsoapConfiguration $event): void
    {
        $lines = explode("\n", $event->buildConfiguration());

        foreach ($lines as $index => $line) {
            $normalized = self::normalizeIcecastOutputLine($line);
            if ($normalized !== $line) {
                $event->replaceLine($index, $normalized);
            }
        }
    }

    public static function normalizeIcecastOutputLine(string $line): string
    {
        if (
            !str_contains($line, 'output.icecast(')
            || str_contains($line, 'icy_metadata =')
            || !str_contains($line, 'send_icy_metadata = true')
        ) {
            return $line;
        }

        return str_replace(
            'send_icy_metadata = true',
            'send_icy_metadata = true, icy_metadata = ["song"]',
            $line,
        );
    }
}
