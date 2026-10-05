<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class FakeCapacityReservationAdapter implements CapacityReservationAdapter
{
    use RecordsAdapterInvocations;

    public ?InfrastructureAdapterResult $nextResult = null;

    public function reserve(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $this->recordInvocation('reserve', $context);

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_manual',
            'Fake adaptateur : intervention manuelle simulée.',
        );
    }
}
