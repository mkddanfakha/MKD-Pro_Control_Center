<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class AdminProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly AdminBootstrapAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_ADMIN));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_ADMIN;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->bootstrapAdmin($context);
    }
}
