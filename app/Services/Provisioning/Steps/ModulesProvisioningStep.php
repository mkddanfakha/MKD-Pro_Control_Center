<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\InstallationModulesAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class ModulesProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly InstallationModulesAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_MODULES));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_MODULES;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->configureModules($context);
    }
}
