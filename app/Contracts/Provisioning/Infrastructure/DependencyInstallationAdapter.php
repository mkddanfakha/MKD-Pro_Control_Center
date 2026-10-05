<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface DependencyInstallationAdapter
{
    public function installDependencies(ProvisioningContext $context): InfrastructureAdapterResult;
}
