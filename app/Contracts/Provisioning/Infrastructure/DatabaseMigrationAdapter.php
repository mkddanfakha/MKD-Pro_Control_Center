<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface DatabaseMigrationAdapter
{
    public function runMigrations(ProvisioningContext $context): InfrastructureAdapterResult;
}
