<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class HealthProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly ApplicationHealthAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_HEALTH));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_HEALTH;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->checkHealth($context);
    }
}
