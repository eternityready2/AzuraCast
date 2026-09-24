<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Controller\Api\Stations\AbstractBackendSettingsAction;

final class LinearLogSettingsAction extends AbstractBackendSettingsAction
{
    protected function fields(): array
    {
        return [
            'linear_log_enabled' => ['type' => 'bool', 'default' => false],
            'linear_log_hours' => ['type' => 'int', 'min' => 1, 'max' => 48, 'default' => 24],
            // Off by default even when the log itself is on: this is the step
            // that makes AutoDJ actually play the log in order.
            'linear_log_playout_enabled' => ['type' => 'bool', 'default' => false],
        ];
    }
}
