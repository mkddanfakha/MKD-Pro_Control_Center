<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver;

final class O2SwitchMigrateCommandPlanResolver
{
    /**
     * @return array{plan: ?O2SwitchMigrateCommandPlan, code: ?string, message: ?string}
     */
    public static function resolve(
        ProvisioningContext $context,
        O2SwitchMigrateConfiguration $configuration,
        O2SwitchMigrateDatabaseTarget $databaseTarget,
    ): array {
        $deployConfiguration = new O2SwitchDeployConfiguration(
            provider: $configuration->provider,
            enabled: true,
            dryRun: true,
            accountLogicalId: $configuration->accountLogicalId,
            deploymentRootBase: $configuration->deploymentRootBase,
            gestionGitRepositoryUrl: 'https://github.com/mkddanfakha/Gestion.git',
            defaultGitRef: '',
            cpanelHost: '',
        );

        $deployPath = O2SwitchDeployPathResolver::resolve($context, $deployConfiguration);
        if ($deployPath === null) {
            return [
                'plan' => null,
                'code' => 'o2switch_migrate_not_configured',
                'message' => 'Répertoire applicatif Gestion indéterminé.',
            ];
        }

        $argv = O2SwitchMigrateArtisanCommandPolicy::productionMigrateArgv();
        if (! O2SwitchMigrateArtisanCommandPolicy::assertProductionMigrateArgv($argv)) {
            return [
                'plan' => null,
                'code' => 'o2switch_migrate_not_configured',
                'message' => 'Plan de migration Artisan invalide.',
            ];
        }

        $migrationFingerprint = hash('sha256', json_encode([
            'argv_policy' => 'migrate_force',
            'database_target_fingerprint' => $databaseTarget->targetFingerprint,
            'working_directory' => $deployPath->absoluteDeployPath,
            $context->targetCommit,
            $context->targetVersion,
        ], JSON_THROW_ON_ERROR));

        return [
            'plan' => new O2SwitchMigrateCommandPlan(
                workingDirectory: $deployPath->absoluteDeployPath,
                artisanMigrateArgv: $argv,
                databaseTarget: $databaseTarget,
                migrationFingerprint: $migrationFingerprint,
            ),
            'code' => null,
            'message' => null,
        ];
    }
}
