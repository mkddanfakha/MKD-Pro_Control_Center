<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchMigrateGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateConfiguration;

final class FakeO2SwitchMigrateGateway implements O2SwitchMigrateGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function runMigrations(
        ProvisioningContext $context,
        O2SwitchMigrateConfiguration $configuration,
        O2SwitchMigrateCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'working_directory' => $commandPlan->workingDirectory,
            'operation' => $commandPlan->safeOperationLabel(),
            'migration_fingerprint' => $commandPlan->migrationFingerprint,
            'migration_target_installation_id' => $commandPlan->databaseTarget->installationId,
            'migration_target_database_name' => $commandPlan->databaseTarget->databaseName,
            'migration_target_database_host' => $commandPlan->databaseTarget->databaseHost,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_migrate',
            'Fake gateway par défaut.',
        );
    }
}
