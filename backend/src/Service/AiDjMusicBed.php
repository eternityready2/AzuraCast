<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDj;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * The music bed file an AI DJ talks over: uploaded from the AI DJ page, kept
 * on the uploads volume next to the station's custom voices.
 */
final class AiDjMusicBed
{
    public const string DIRECTORY = '/var/azuracast/storage/uploads/ai-dj-beds';

    private const array EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'opus', 'flac', 'wav'];

    /** The save request carries the file, so it has to fit PHP's 50M request limit. */
    private const int MAX_BYTES = 30 * 1024 * 1024;

    /** Replace the DJ's bed with an uploaded file (base64, as sent by the AI DJ page). */
    public function store(AiDj $dj, string $fileName, string $base64): void
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                'The music bed must be an audio file (' . implode(', ', self::EXTENSIONS) . ').'
            );
        }

        // A data URL from the browser's file reader, or the bare base64.
        $comma = strpos($base64, ',');
        $data = base64_decode(false === $comma ? $base64 : substr($base64, $comma + 1), true);
        if (false === $data || '' === $data) {
            throw new InvalidArgumentException('The music bed did not upload correctly; please try again.');
        }
        if (strlen($data) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The music bed is too large; the limit is 30 MB.');
        }

        if (!is_dir(self::DIRECTORY) && !@mkdir(self::DIRECTORY, 0755, true) && !is_dir(self::DIRECTORY)) {
            throw new InvalidArgumentException('The music bed folder could not be created.');
        }

        $name = trim((string)preg_replace('/[^A-Za-z0-9 ._()-]+/', '', pathinfo($fileName, PATHINFO_FILENAME)));
        $path = sprintf('%s/dj%d - %s.%s', self::DIRECTORY, $dj->getId(), '' === $name ? 'music bed' : $name, $extension);
        $incoming = $path . '.incoming';

        if (false === file_put_contents($incoming, $data)) {
            throw new InvalidArgumentException('The music bed could not be saved.');
        }

        if ($this->duration($incoming) < 1.0) {
            @unlink($incoming);
            throw new InvalidArgumentException('That file does not play as audio.');
        }

        $old = $dj->getBackgroundAudioPath();
        rename($incoming, $path);
        if (null !== $old && $old !== $path && str_starts_with($old, self::DIRECTORY . '/')) {
            @unlink($old);
        }

        $dj->setBackgroundAudioPath($path);
    }

    public function remove(AiDj $dj): void
    {
        $old = $dj->getBackgroundAudioPath();
        if (null !== $old && str_starts_with($old, self::DIRECTORY . '/')) {
            @unlink($old);
        }

        $dj->setBackgroundAudioPath(null);
    }

    /** Length of an audio file in seconds; 0.0 when it cannot be read. */
    public function duration(string $path): float
    {
        $probe = new Process([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);
        $probe->setTimeout(10);
        $probe->run();

        return $probe->isSuccessful() ? (float)trim($probe->getOutput()) : 0.0;
    }
}
