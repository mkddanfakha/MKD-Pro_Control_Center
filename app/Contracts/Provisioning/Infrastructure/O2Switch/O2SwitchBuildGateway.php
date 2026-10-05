<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildConfiguration;

/**
 * Port futur vers l'exécution distante du build Vite — TASK 363.
 */
interface O2SwitchBuildGateway
{
    public function buildApplication(
        ProvisioningContext $context,
        O2SwitchBuildConfiguration $configuration,
        O2SwitchBuildCommandPlan $commandPlan,
    ): InfrastructureAdapterResult;
}
