<?php

namespace App\Services\Provisioning\Infrastructure\Unavailable;

use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\UnavailableInfrastructureAdapterSupport;

final class UnavailableEnvironmentConfigurationAdapter implements EnvironmentConfigurationAdapter
{
    public function configureEnvironment(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return UnavailableInfrastructureAdapterSupport::manualInterventionForContract('environment_configuration', $context);
    }
}
