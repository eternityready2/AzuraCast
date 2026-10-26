<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

/**
 * How closely the song picked for a log line has to match the length asked
 * for (LinearLogRefill::pickReplacement()).
 */
enum LinearLogSongMatch
{
    /**
     * About that long by its file: one of the few closest, drawn at random, so
     * a slot is not always given the same song.
     */
    case About;

    /**
     * As near that long as the playlist has, by its file, which is how the log
     * times a line: the closest wins, and others share the draw only when they
     * are within the Top-of-Hour swap's own tolerance of it.
     */
    case Closest;

    /**
     * An hour's last song, which has to end on the ID: as Closest, by the length
     * it holds the air for (AiredLength), as the Top-of-Hour swap measures the
     * song it lands there.
     */
    case OnId;
}
