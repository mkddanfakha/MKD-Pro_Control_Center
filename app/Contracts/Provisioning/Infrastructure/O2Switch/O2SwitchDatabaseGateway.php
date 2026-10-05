<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;

/**
 * Port futur vers la création MySQL o2switch (cPanel UAPI documenté) — TASK 359.
 *
 * Aucune URL o2switch propriétaire : l'implémentation HTTP cible l'UAPI cPanel officielle
 * lorsqu'elle sera explicitement enregistrée (hors production par défaut).
 */
interface O2SwitchDatabaseGateway
{
    public function provisionDatabase(
        ProvisioningContext $context,
        O2SwitchDatabaseConfiguration $configuration,
    ): InfrastructureAdapterResult;
}
