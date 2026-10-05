<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface InstallationReadinessPersistenceAdapter
{
    public function persistMarker(ProvisioningContext $context, string $marker): InfrastructureAdapterResult;
}
