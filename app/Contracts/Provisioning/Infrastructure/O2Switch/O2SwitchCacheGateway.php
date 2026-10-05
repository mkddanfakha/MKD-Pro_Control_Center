<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheConfiguration;

/**
 * Port futur vers l'exécution distante des commandes de cache Laravel — TASK 366.
 */
interface O2SwitchCacheGateway
{
    public function warmCache(
        ProvisioningContext $context,
        O2SwitchCacheConfiguration $configuration,
        O2SwitchCacheCommandPlan $commandPlan,
    ): InfrastructureAdapterResult;
}
