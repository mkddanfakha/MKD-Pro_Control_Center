<?php

namespace App\Services\Provisioning\Steps;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Services\Provisioning\Infrastructure\InfrastructureAdapterResultMapper;

/**
 * Step qui délègue à un contrat d'adaptateur d'infrastructure (TASK 354).
 */
abstract class DelegatingInfrastructureAdapterStep extends AbstractProductionProvisioningStep
{
    abstract protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult;

    public function execute(ProvisioningContext $context): ProvisioningStepResult
    {
        return InfrastructureAdapterResultMapper::toStepResult(
            $this->stepKey(),
            $this->invokeAdapter($context),
        );
    }
}
