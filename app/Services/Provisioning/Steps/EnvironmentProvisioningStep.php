<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class EnvironmentProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly EnvironmentConfigurationAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_ENVIRONMENT));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_ENVIRONMENT;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->configureEnvironment($context);
    }
}
