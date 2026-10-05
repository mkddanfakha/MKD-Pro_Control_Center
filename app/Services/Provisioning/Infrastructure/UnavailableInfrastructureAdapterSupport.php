<?php

namespace App\Services\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Réponses par défaut lorsqu'aucune implémentation réelle n'est branchée (TASK 354).
 */
final class UnavailableInfrastructureAdapterSupport
{
    public static function manualInterventionForContract(
        string $contractKey,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'infrastructure_adapter_unavailable',
            sprintf(
                'Le contrat « %s » n\'a pas d\'implémentation opérationnelle : aucune opération externe n\'a été effectuée (installation #%d).',
                $contractKey,
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'contract_only',
                'contract' => $contractKey,
            ],
            metadata: [
                'implementation_state' => 'contract_only',
                'requires_manual_intervention' => true,
            ],
        );
    }
}
