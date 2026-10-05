<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface ApplicationHealthAdapter
{
    public function checkHealth(ProvisioningContext $context): InfrastructureAdapterResult;
}
