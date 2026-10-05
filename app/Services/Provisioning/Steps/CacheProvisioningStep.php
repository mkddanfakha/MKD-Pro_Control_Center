<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class CacheProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly CacheWarmupAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_CACHE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_CACHE;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->warmCache($context);
    }
}
