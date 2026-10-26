<?php

declare(strict_types=1);

namespace App\Entity\Api;

use App\Entity\StationBackendConfiguration;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Api_StationPlayoutControls',
    required: ['*'],
    type: 'object'
)]
final readonly class StationPlayoutControls
{
    #[OA\Property]
    public bool $stretch_squeeze_enabled;

    /** @deprecated Superseded by stretch_max_percent / squeeze_max_percent. */
    #[OA\Property]
    public float $stretch_squeeze_max_percent;

    #[OA\Property]
    public float $stretch_max_percent;

    #[OA\Property]
    public float $squeeze_max_percent;

    #[OA\Property]
    public bool $smart_duck_enabled;

    #[OA\Property]
    public float $smart_duck_attenuation;

    #[OA\Property]
    public float $smart_duck_delay;

    public function __construct(StationBackendConfiguration $config)
    {
        $this->stretch_squeeze_enabled = $config->playout_stretch_squeeze_enabled;
        $this->stretch_squeeze_max_percent = $config->playout_stretch_squeeze_max_percent;
        $this->stretch_max_percent = $config->playout_stretch_max_percent;
        $this->squeeze_max_percent = $config->playout_squeeze_max_percent;
        $this->smart_duck_enabled = $config->playout_smart_duck_enabled;
        $this->smart_duck_attenuation = $config->playout_smart_duck_attenuation;
        $this->smart_duck_delay = $config->playout_smart_duck_delay;
    }
}
