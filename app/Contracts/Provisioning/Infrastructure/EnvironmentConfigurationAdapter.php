<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface EnvironmentConfigurationAdapter
{
    public function configureEnvironment(ProvisioningContext $context): InfrastructureAdapterResult;
}
