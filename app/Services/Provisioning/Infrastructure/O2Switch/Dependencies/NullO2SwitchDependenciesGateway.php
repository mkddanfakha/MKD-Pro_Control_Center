<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Dependencies;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDependenciesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchDependenciesGateway implements O2SwitchDependenciesGateway
{
    public function installDependencies(
        ProvisioningContext $context,
        O2SwitchDependenciesConfiguration $configuration,
        O2SwitchDependenciesCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_dependencies_protocol_pending',
            sprintf(
                'Aucun gateway Dependencies o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'dependency_installation',
                'operations_planned' => $commandPlan->safeOperationLabels(),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'dependency_installation',
                'installation_id' => $context->installationId,
                'lock_fingerprint' => $commandPlan->lockFingerprint,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
