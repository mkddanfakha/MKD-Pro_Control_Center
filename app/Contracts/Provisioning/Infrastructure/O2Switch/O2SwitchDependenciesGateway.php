<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesConfiguration;

/**
 * Port futur vers l'exécution distante Composer/npm — TASK 362.
 */
interface O2SwitchDependenciesGateway
{
    public function installDependencies(
        ProvisioningContext $context,
        O2SwitchDependenciesConfiguration $configuration,
        O2SwitchDependenciesCommandPlan $commandPlan,
    ): InfrastructureAdapterResult;
}
