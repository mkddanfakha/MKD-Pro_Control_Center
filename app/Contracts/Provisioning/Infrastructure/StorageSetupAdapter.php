<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface StorageSetupAdapter
{
    public function configureStorage(ProvisioningContext $context): InfrastructureAdapterResult;
}
