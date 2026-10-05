<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

final class O2SwitchDeployPathResolution
{
    public function __construct(
        public readonly string $absoluteDeployPath,
        public readonly string $relativeSegment,
    ) {}
}
