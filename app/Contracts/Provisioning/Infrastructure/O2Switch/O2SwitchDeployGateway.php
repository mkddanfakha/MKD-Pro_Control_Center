<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;

/**
 * Port futur vers le déploiement Gestion sur o2switch (cPanel Git / SSH) — TASK 360.
 */
interface O2SwitchDeployGateway
{
    public function deployApplication(
        ProvisioningContext $context,
        O2SwitchDeployConfiguration $configuration,
    ): InfrastructureAdapterResult;
}
