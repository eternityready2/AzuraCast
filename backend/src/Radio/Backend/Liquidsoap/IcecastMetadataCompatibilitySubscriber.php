<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Liquidsoap / Icecast compatibility for metadata handling.
 *
 * Icecast 2.5 warns when an ICY metadata update submits its synthesized `song`
 * field together with `artist` and/or `title`. Limit Icecast ICY updates to the
 * listener-compatible `song` value; Liquidsoap still synthesizes it from the
 * normal artist/title metadata.
 *
 * Some MP3 files also carry large PRIV/XMP frames. They are private/binary
 * metadata and should not be passed through Liquidsoap's UTF-8 recoder. Append
 * them to Liquidsoap's existing exclusions rather than replacing its defaults.
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
            '# Keep Liquidsoap defaults and add private/binary MP3 metadata exclusions.',
            'settings.request.metadata_decoders.recode.exclude := list.append(',
            '    settings.request.metadata_decoders.recode.exclude(),',
            '    ["priv", "id3v2_priv.xmp"]',
            ')',
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
