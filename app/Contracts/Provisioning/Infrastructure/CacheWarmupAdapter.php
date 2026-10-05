<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface CacheWarmupAdapter
{
    public function warmCache(ProvisioningContext $context): InfrastructureAdapterResult;
}
