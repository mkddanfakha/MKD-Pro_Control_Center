<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface AdminBootstrapAdapter
{
    public function bootstrapAdmin(ProvisioningContext $context): InfrastructureAdapterResult;
}
