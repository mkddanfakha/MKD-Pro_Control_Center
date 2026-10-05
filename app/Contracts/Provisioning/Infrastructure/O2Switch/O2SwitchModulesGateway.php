<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesPlan;

/**
 * Port futur vers la configuration modules Gestion — TASK 368.
 */
interface O2SwitchModulesGateway
{
    public function configureModules(
        ProvisioningContext $context,
        O2SwitchModulesConfiguration $configuration,
        O2SwitchModulesPlan $plan,
    ): InfrastructureAdapterResult;
}
