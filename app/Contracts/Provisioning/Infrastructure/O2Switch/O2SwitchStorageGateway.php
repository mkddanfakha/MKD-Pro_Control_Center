<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;

/**
 * Port futur vers la préparation filesystem distante — TASK 365.
 */
interface O2SwitchStorageGateway
{
    public function configureStorage(
        ProvisioningContext $context,
        O2SwitchStorageConfiguration $configuration,
        O2SwitchStorageCommandPlan $commandPlan,
    ): InfrastructureAdapterResult;
}
