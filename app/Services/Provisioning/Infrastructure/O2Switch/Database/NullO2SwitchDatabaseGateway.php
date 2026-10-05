<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Database;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDatabaseGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Gateway placeholder — protocole MySQL o2switch non branché en production (TASK 359).
 */
final class NullO2SwitchDatabaseGateway implements O2SwitchDatabaseGateway
{
    public function provisionDatabase(
        ProvisioningContext $context,
        O2SwitchDatabaseConfiguration $configuration,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_database_protocol_pending',
            sprintf(
                'Aucun gateway MySQL o2switch opérationnel n\'est enregistré pour provisionner la base (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'client_database',
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'client_database',
                'provider' => $configuration->provider,
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
