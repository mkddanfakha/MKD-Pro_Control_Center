<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface InstallationModulesAdapter
{
    public function configureModules(ProvisioningContext $context): InfrastructureAdapterResult;
}
