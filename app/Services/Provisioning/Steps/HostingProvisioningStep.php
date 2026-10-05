<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class HostingProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly HostingSpaceAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_HOSTING));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_HOSTING;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->prepareHosting($context);
    }
}
