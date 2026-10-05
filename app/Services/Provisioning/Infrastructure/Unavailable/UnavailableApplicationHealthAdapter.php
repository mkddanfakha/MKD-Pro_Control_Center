<?php

namespace App\Services\Provisioning\Infrastructure\Unavailable;

use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\UnavailableInfrastructureAdapterSupport;

final class UnavailableApplicationHealthAdapter implements ApplicationHealthAdapter
{
    public function checkHealth(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return UnavailableInfrastructureAdapterSupport::manualInterventionForContract('health_check', $context);
    }
}
