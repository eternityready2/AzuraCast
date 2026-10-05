<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use RuntimeException;

/**
 * Playout took a line the running build was about to replace. The plan was
 * simulated without it, so saving it would put a second line in that slot;
 * the build starts over from the log as it is now.
 */
final class LinearLogPlanConflict extends RuntimeException
{
}
