<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\DmcaCompliance;

use App\Controller\Api\Stations\AbstractBackendSettingsAction;
use App\Radio\AutoDJ\DmcaComplianceListener;

final class SettingsAction extends AbstractBackendSettingsAction
{
    protected function fields(): array
    {
        return [
            'dmca_compliance_enabled' => ['type' => 'bool', 'default' => false],
            'dmca_window_minutes' => [
                'type' => 'int', 'min' => 60, 'max' => 360,
                'default' => DmcaComplianceListener::DEFAULT_WINDOW_MINUTES,
            ],
            'dmca_max_song_plays' => [
                'type' => 'int', 'min' => 1, 'max' => 10,
                'default' => DmcaComplianceListener::DEFAULT_MAX_SONG_PLAYS,
            ],
            'dmca_max_consecutive_song' => [
                'type' => 'int', 'min' => 1, 'max' => 5,
                'default' => DmcaComplianceListener::DEFAULT_MAX_CONSECUTIVE_SONG_PLAYS,
            ],
            'dmca_max_album_plays' => [
                'type' => 'int', 'min' => 1, 'max' => 10,
                'default' => DmcaComplianceListener::DEFAULT_MAX_ALBUM_PLAYS,
            ],
            'dmca_max_artist_plays' => [
                'type' => 'int', 'min' => 1, 'max' => 10,
                'default' => DmcaComplianceListener::DEFAULT_MAX_ARTIST_PLAYS,
            ],
            'dmca_max_consecutive_artist' => [
                'type' => 'int', 'min' => 1, 'max' => 10,
                'default' => DmcaComplianceListener::DEFAULT_MAX_CONSECUTIVE_ARTIST_PLAYS,
            ],
        ];
    }
}
