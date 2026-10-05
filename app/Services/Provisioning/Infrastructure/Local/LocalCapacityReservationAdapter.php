<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Support\Provisioning\CapacityReservationContract;

final class LocalCapacityReservationAdapter extends AbstractLocalInfrastructureAdapter implements CapacityReservationAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'capacity_reservation', 'capacity');
    }

    public function reserve(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => CapacityReservationContract::logicalSucceededOutputSummary(
                $installationId,
            ),
        );
    }
}
