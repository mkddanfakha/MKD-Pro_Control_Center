<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class ReserveProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly CapacityReservationAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_RESERVE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_RESERVE;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->reserve($context);
    }
}
