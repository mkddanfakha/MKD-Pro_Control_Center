<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchGestionEnvBuildResult;

/**
 * Port futur vers l'écriture du `.env` Gestion sur o2switch — TASK 361.
 */
interface O2SwitchEnvironmentGateway
{
    public function configureEnvironment(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
        O2SwitchGestionEnvBuildResult $buildResult,
    ): InfrastructureAdapterResult;
}
