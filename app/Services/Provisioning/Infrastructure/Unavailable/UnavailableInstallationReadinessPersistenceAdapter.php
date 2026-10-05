<?php

namespace App\Services\Provisioning\Infrastructure\Unavailable;

use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\UnavailableInfrastructureAdapterSupport;

final class UnavailableInstallationReadinessPersistenceAdapter implements InstallationReadinessPersistenceAdapter
{
    public function persistMarker(ProvisioningContext $context, string $marker): InfrastructureAdapterResult
    {
        return UnavailableInfrastructureAdapterSupport::manualInterventionForContract(
            'readiness_persistence:'.$marker,
            $context,
        );
    }
}
