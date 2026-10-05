<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Dependencies;

use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver;
final class O2SwitchDependenciesCommandPlanResolver
{
    /**
     * @return array{plan: ?O2SwitchDependenciesCommandPlan, code: ?string, message: ?string}
     */
    public static function resolve(
        ProvisioningContext $context,
        O2SwitchDependenciesConfiguration $configuration,
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
                'code' => 'o2switch_dependencies_not_configured',
                'message' => 'Répertoire applicatif Gestion indéterminé.',
            ];
        }

        $composerArgv = [
            'composer',
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--optimize-autoloader',
            '--no-dev',
        ];

        $npmArgv = $configuration->nodeDependenciesRequired
            ? ['npm', 'ci']
            : null;

        $lockFingerprint = hash('sha256', json_encode([
            'composer.lock',
            'package-lock.json',
            $configuration->nodeDependenciesRequired,
        ], JSON_THROW_ON_ERROR));

        return [
            'plan' => new O2SwitchDependenciesCommandPlan(
                workingDirectory: $deployPath->absoluteDeployPath,
                composerArgv: $composerArgv,
                npmArgv: $npmArgv,
                lockFingerprint: $lockFingerprint,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    public static function validateRuntimeCompatibility(
        O2SwitchDependenciesConfiguration $configuration,
        ?string $reportedPhpVersion,
    ): ?string {
        if ($reportedPhpVersion === null || trim($reportedPhpVersion) === '') {
            return null;
        }

        $minimum = $configuration->gestionPhpVersionMinimum;
        if ($minimum === '') {
            return null;
        }

        if (version_compare($reportedPhpVersion, $minimum, '<')) {
            return 'o2switch_dependencies_runtime_incompatible';
        }

        return null;
    }
}
