<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface ClientDatabaseAdapter
{
    public function provisionDatabase(ProvisioningContext $context): InfrastructureAdapterResult;
}
