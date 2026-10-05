<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface ApplicationDeployAdapter
{
    public function deployApplication(ProvisioningContext $context): InfrastructureAdapterResult;
}
