<?php

declare(strict_types=1);

namespace Unit;

use App\Radio\Backend\Liquidsoap\IcecastMetadataCompatibilitySubscriber;
use Codeception\Test\Unit;

final class IcecastMetadataCompatibilitySubscriberTest extends Unit
{
    public function testIcecastIcyMetadataIsLimitedToSong(): void
    {
        $line = 'output.icecast(%ffmpeg(format="mp3", %audio.copy), id="local_1", send_icy_metadata = true, radio)';

        self::assertSame(
            'output.icecast(%ffmpeg(format="mp3", %audio.copy), id="local_1", send_icy_metadata = true, icy_metadata = ["song"], radio)',
            IcecastMetadataCompatibilitySubscriber::normalizeIcecastOutputLine($line),
        );
    }

    public function testShoutcastAndDisabledIcyLinesAreUntouched(): void
    {
        $shoutcast = 'output.shoutcast(%mp3, send_icy_metadata = true, radio)';
        $disabled = 'output.icecast(%ogg, send_icy_metadata = false, radio)';

        self::assertSame(
            $shoutcast,
            IcecastMetadataCompatibilitySubscriber::normalizeIcecastOutputLine($shoutcast),
        );
        self::assertSame(
            $disabled,
            IcecastMetadataCompatibilitySubscriber::normalizeIcecastOutputLine($disabled),
        );
    }

    public function testExistingIcyMetadataSettingIsNotDuplicated(): void
    {
        $line = 'output.icecast(%mp3, send_icy_metadata = true, icy_metadata = ["song"], radio)';

        self::assertSame(
            $line,
            IcecastMetadataCompatibilitySubscriber::normalizeIcecastOutputLine($line),
        );
    }
}
