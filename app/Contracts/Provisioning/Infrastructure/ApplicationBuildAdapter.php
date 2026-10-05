<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface ApplicationBuildAdapter
{
    public function buildApplication(ProvisioningContext $context): InfrastructureAdapterResult;
}
