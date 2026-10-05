<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateConfiguration;

/**
 * Port futur vers l'exécution distante de `php artisan migrate --force` — TASK 364.
 */
interface O2SwitchMigrateGateway
{
    public function runMigrations(
        ProvisioningContext $context,
        O2SwitchMigrateConfiguration $configuration,
        O2SwitchMigrateCommandPlan $commandPlan,
    ): InfrastructureAdapterResult;
}
