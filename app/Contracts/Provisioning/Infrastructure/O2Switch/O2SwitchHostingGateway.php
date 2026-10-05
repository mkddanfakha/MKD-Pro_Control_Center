<?php

namespace App\Contracts\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\O2SwitchHostingConfiguration;

/**
 * Port futur vers o2switch pour l'hébergement client — sans URL/SDK inventé (TASK 357).
 *
 * Une implémentation HTTP/SSH ne sera ajoutée que lorsqu'un protocole officiel
 * ou un runbook d'accès sera validé par l'équipe.
 */
interface O2SwitchHostingGateway
{
    public function prepareHostingSpace(
        ProvisioningContext $context,
        O2SwitchHostingConfiguration $configuration,
    ): InfrastructureAdapterResult;
}
