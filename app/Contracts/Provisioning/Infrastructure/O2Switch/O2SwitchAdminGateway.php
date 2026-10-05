<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminPlan;

/**
 * Port futur vers l'initialisation admin Gestion — TASK 367.
 */
interface O2SwitchAdminGateway
{
    public function bootstrapAdmin(
        ProvisioningContext $context,
        O2SwitchAdminConfiguration $configuration,
        O2SwitchAdminPlan $plan,
    ): InfrastructureAdapterResult;
}
