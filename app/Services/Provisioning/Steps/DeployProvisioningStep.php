<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class DeployProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly ApplicationDeployAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_DEPLOY));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_DEPLOY;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->deployApplication($context);
    }
}
