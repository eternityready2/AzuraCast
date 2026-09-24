<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Controller\Api\Stations\AbstractBackendSettingsAction;
use App\Radio\AutoDJ\AiDjTalkRules;

final class TalkRulesAction extends AbstractBackendSettingsAction
{
    protected function fields(): array
    {
        return array_map(
            static fn(array $limit): array => ['type' => 'int'] + $limit,
            AiDjTalkRules::LIMITS,
        );
    }
}
