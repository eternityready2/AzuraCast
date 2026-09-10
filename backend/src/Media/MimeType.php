<?php

declare(strict_types=1);

namespace App\Media;

use League\MimeTypeDetection\FinfoMimeTypeDetector;

final class MimeType
{
    private static FinfoMimeTypeDetector $detector;

    private static array $processableTypes = [
        'audio/aiff', // aiff (Audio Interchange File Format)
        'audio/flac', // MIME type used by some FLAC files
        'audio/mp4', // m4a mp4a
        'audio/mpeg', // mpga mp2 mp2a mp3 m2a m3a
        'audio/ogg', // oga ogg spx
        'audio/s3m', // s3m (ScreamTracker 3 Module)
        'audio/wav', // wav
        'audio/xm', // xm
        'audio/vnd.wave', // alt for wav (RFC 2361)
        'audio/x-aac', // aac
        'audio/x-aiff', // alt for aiff
        'audio/x-flac', // flac
        'audio/x-m4a', // alt for m4a/mp4a
        'audio/x-mod', // stm, alt for xm
        'audio/x-s3m', // alt for s3m
        'audio/x-wav', // alt for wav
        'audio/x-ms-wma', // wma (Windows Media Audio)
        'video/mp4', // some MP4 audio files are recognized as this (#3569)
        'video/x-ms-asf', // asf / wmv / alt for wma
    ];

    private static array $imageTypes = [
        'image/gif', // gif
        'image/jpeg', // jpg/jpeg
        'image/png', // png
    ];

    /**
     * @return string[]
     */
    public static function getProcessableTypes(): array
    {
        return self::$processableTypes;
    }

    public static function getMimeTypeDetector(): FinfoMimeTypeDetector
    {
        if (!isset(self::$detector)) {
            self::$detector = new FinfoMimeTypeDetector(
                extensionMap: new MimeTypeExtensionMap()
            );
        }

        return self::$detector;
    }

    public static function getMimeTypeFromFile(string $path): string
    {
        $fileMimeType = self::getMimeTypeDetector()->detectMimeTypeFromFile($path);

        if ('application/octet-stream' === $fileMimeType) {
            // Some valid MP3 files have a large ID3v2 tag before the first MPEG audio frame.
            // libmagic/finfo can classify these extensionless temporary downloads as generic
            // octet-stream. Verify the ID3 tag and locate a real MPEG frame before accepting it.
            if (self::looksLikeMp3WithId3Tag($path)) {
                return 'audio/mpeg';
            }

            $fileMimeType = null;
        }

        return $fileMimeType ?? self::getMimeTypeFromPath($path);
    }

    private static function looksLikeMp3WithId3Tag(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }

        try {
            $header = fread($handle, 10);
            if (false === $header || strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
                return false;
            }

            // ID3v2 stores its payload size as four 7-bit (synchsafe) bytes.
            $tagSize = ((ord($header[6]) & 0x7F) << 21)
                | ((ord($header[7]) & 0x7F) << 14)
                | ((ord($header[8]) & 0x7F) << 7)
                | (ord($header[9]) & 0x7F);
            $audioOffset = 10 + $tagSize;

            // ID3v2.4 can include a 10-byte footer after the payload.
            if ((ord($header[5]) & 0x10) !== 0) {
                $audioOffset += 10;
            }

            if (0 !== fseek($handle, $audioOffset)) {
                return false;
            }

            // Allow normal tag padding before the first audio frame.
            $probe = fread($handle, 4096);
            if (false === $probe) {
                return false;
            }

            $probeLength = strlen($probe);
            for ($i = 0; $i + 1 < $probeLength; ++$i) {
                $first = ord($probe[$i]);
                $second = ord($probe[$i + 1]);

                if ($first !== 0xFF || ($second & 0xE0) !== 0xE0) {
                    continue;
                }

                // Exclude reserved MPEG version and layer bit patterns.
                $versionBits = ($second >> 3) & 0x03;
                $layerBits = ($second >> 1) & 0x03;
                if ($versionBits !== 0x01 && $layerBits !== 0x00) {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    public static function getMimeTypeFromPath(string $path): string
    {
        return self::getMimeTypeDetector()->detectMimeTypeFromPath($path)
            ?? 'application/octet-stream';
    }

    public static function isPathProcessable(string $path): bool
    {
        $mimeType = self::getMimeTypeFromPath($path);

        return in_array($mimeType, self::$processableTypes, true);
    }

    public static function isPathImage(string $path): bool
    {
        $mimeType = self::getMimeTypeFromPath($path);

        return in_array($mimeType, self::$imageTypes, true);
    }

    public static function isFileProcessable(string $path): bool
    {
        $mimeType = self::getMimeTypeFromFile($path);

        return in_array($mimeType, self::$processableTypes, true);
    }
}
