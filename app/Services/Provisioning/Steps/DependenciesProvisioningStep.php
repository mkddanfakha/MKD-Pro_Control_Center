<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class DependenciesProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly DependencyInstallationAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_DEPENDENCIES));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_DEPENDENCIES;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->installDependencies($context);
    }
}
