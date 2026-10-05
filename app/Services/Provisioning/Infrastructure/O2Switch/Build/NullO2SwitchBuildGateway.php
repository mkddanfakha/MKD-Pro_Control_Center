<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Build;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchBuildGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchBuildGateway implements O2SwitchBuildGateway
{
    public function buildApplication(
        ProvisioningContext $context,
        O2SwitchBuildConfiguration $configuration,
        O2SwitchBuildCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_build_protocol_pending',
            sprintf(
                'Aucun gateway Build o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'application_build',
                'operation_planned' => $commandPlan->safeOperationLabel(),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'application_build',
                'installation_id' => $context->installationId,
                'build_fingerprint' => $commandPlan->buildFingerprint,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
