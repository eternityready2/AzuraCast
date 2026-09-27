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
            'linear_log_rule_enforce_windows' => ['type' => 'bool', 'default' => true],
            'linear_log_rule_drop_outside_window' => ['type' => 'bool', 'default' => true],
            'linear_log_rule_refill_dropped' => ['type' => 'bool', 'default' => true],
        ];
    }
}
