<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class BuildProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly ApplicationBuildAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_BUILD));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_BUILD;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->buildApplication($context);
    }
}
