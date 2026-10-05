<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchStorageGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchStorageGateway implements O2SwitchStorageGateway
{
    public function configureStorage(
        ProvisioningContext $context,
        O2SwitchStorageConfiguration $configuration,
        O2SwitchStorageCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_storage_protocol_pending',
            sprintf(
                'Aucun gateway Storage o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'storage_setup',
                'operation_planned' => $commandPlan->safeOperationLabel(),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'storage_setup',
                'installation_id' => $context->installationId,
                'storage_fingerprint' => $commandPlan->storageFingerprint,
                ...$commandPlan->deploymentTarget->safePublicMetadata(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
