<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Services\Provisioning\Infrastructure\InfrastructureAdapterResultMapper;

/**
 * Palier readiness Control Center — délégation contrat (TASK 354 / local TASK 355).
 */
abstract class ReadinessContractStep extends AbstractProductionProvisioningStep
{
    public function __construct(
        int $canonicalOrder,
        protected readonly InstallationReadinessPersistenceAdapter $readinessAdapter,
    ) {
        parent::__construct($canonicalOrder);
    }

    abstract protected function readinessMarker(): string;

    public function execute(ProvisioningContext $context): ProvisioningStepResult
    {
        return InfrastructureAdapterResultMapper::toStepResult(
            $this->stepKey(),
            $this->readinessAdapter->persistMarker($context, $this->readinessMarker()),
        );
    }
}
