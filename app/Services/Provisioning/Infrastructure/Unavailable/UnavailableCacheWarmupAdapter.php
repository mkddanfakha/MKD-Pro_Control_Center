<?php

namespace App\Services\Provisioning\Infrastructure\Unavailable;

use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\UnavailableInfrastructureAdapterSupport;

final class UnavailableCacheWarmupAdapter implements CacheWarmupAdapter
{
    public function warmCache(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return UnavailableInfrastructureAdapterSupport::manualInterventionForContract('cache_warmup', $context);
    }
}
