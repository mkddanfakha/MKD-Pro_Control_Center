<?php

namespace App\Services\Provisioning\Infrastructure\Unavailable;

use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Support\Provisioning\CapacityReservationContract;

final class UnavailableCapacityReservationAdapter implements CapacityReservationAdapter
{
    public function reserve(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return CapacityReservationContract::infrastructureAdapterUnavailable($context->installationId);
    }
}
