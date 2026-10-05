<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHostingGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Gateway placeholder — protocole o2switch non branché (TASK 357).
 */
final class NullO2SwitchHostingGateway implements O2SwitchHostingGateway
{
    public function prepareHostingSpace(
        ProvisioningContext $context,
        O2SwitchHostingConfiguration $configuration,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_hosting_protocol_pending',
            sprintf(
                'Aucun protocole o2switch opérationnel n\'est configuré pour préparer l\'hébergement (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'hosting_panel',
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'hosting_panel',
                'provider' => $configuration->provider,
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
