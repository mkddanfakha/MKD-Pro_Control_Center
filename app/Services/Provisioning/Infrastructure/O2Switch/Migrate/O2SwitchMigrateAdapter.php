<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchMigrateGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchMigrateAdapter implements DatabaseMigrationAdapter
{
    public function __construct(
        private readonly O2SwitchMigrateGateway $gateway,
        private readonly ?O2SwitchMigrateConfiguration $configuration = null,
    ) {}

    public function runMigrations(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchMigrateConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_migrate_disabled',
                'Migrate o2switch désactivé (PROVISIONING_O2SWITCH_MIGRATE_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_migrate_not_configured',
                'Migrate o2switch activé mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $targetResolved = O2SwitchMigrateDatabaseTargetResolver::resolve(
            $context,
            $configuration->forbiddenMigrationDatabaseNames,
        );

        if ($targetResolved['target'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $targetResolved['code'] ?? 'o2switch_migrate_database_target_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $targetResolved['message'] ?? 'Cible base invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'implementation_state' => 'database_target_rejected',
                    'contract' => 'database_migrate',
                ],
                metadata: [
                    'implementation_state' => 'database_target_rejected',
                    'operation' => 'database_migrate',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $databaseTarget = $targetResolved['target'];

        $resolved = O2SwitchMigrateCommandPlanResolver::resolve($context, $configuration, $databaseTarget);
        if ($resolved['plan'] === null) {
            return $this->manualIntervention(
                $resolved['code'] ?? 'o2switch_migrate_not_configured',
                $resolved['message'] ?? 'Plan de migration invalide.',
                $configuration,
                $context,
            );
        }

        $commandPlan = $resolved['plan'];

        if (! $configuration->dryRun && ! self::environmentPrepared($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_migrate_environment_not_ready',
                sprintf(
                    'Prérequis Environment (.env Gestion / DB) non confirmés (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'database_migrate',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'database_migrate',
                    'installation_id' => $context->installationId,
                    ...$databaseTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'database_migrate',
                    'working_directory' => $commandPlan->workingDirectory,
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'database_migrate',
                    'installation_id' => $context->installationId,
                    'migration_fingerprint' => $commandPlan->migrationFingerprint,
                    'laravel_migrate_policy' => 'migrate_force',
                    ...$databaseTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_migrate_not_configured',
                'Migrate o2switch activé mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->runMigrations($context, $configuration, $commandPlan);
    }

    private static function environmentPrepared(ProvisioningContext $context): bool
    {
        if (($context->externalReferences['gestion_environment_prepared'] ?? false) === true) {
            return true;
        }

        $username = $context->externalReferences['database_username'] ?? null;

        return is_string($username) && trim($username) !== '';
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchMigrateConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'database_migrate',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'database_migrate',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
