<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use RuntimeException;

/**
 * The next queued item is held on purpose (it would be cut by the Top-of-Hour
 * ID, or a strict programme holds the air first). Liquidsoap reads the refusal
 * as "no request yet" and asks again shortly; nothing is wrong. Reported as an
 * error, every Liquidsoap retry logged one: 1,765 on Wed 2026-10-07, burying
 * the real errors.
 */
final class NextSongHeldException extends RuntimeException
{
}
