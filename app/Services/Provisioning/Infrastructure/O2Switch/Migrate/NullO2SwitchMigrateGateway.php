<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchMigrateGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchMigrateGateway implements O2SwitchMigrateGateway
{
    public function runMigrations(
        ProvisioningContext $context,
        O2SwitchMigrateConfiguration $configuration,
        O2SwitchMigrateCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_migrate_protocol_pending',
            sprintf(
                'Aucun gateway Migrate o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'database_migrate',
                'operation_planned' => $commandPlan->safeOperationLabel(),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'database_migrate',
                'installation_id' => $context->installationId,
                'migration_fingerprint' => $commandPlan->migrationFingerprint,
                ...$commandPlan->databaseTarget->safePublicMetadata(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
