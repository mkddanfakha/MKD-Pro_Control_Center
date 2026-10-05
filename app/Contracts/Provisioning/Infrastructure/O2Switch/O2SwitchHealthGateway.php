<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthPlan;

/**
 * Port futur vers les contrôles de santé Gestion distants — TASK 369.
 */
interface O2SwitchHealthGateway
{
    public function checkHealth(
        ProvisioningContext $context,
        O2SwitchHealthConfiguration $configuration,
        O2SwitchHealthPlan $plan,
    ): InfrastructureAdapterResult;
}
