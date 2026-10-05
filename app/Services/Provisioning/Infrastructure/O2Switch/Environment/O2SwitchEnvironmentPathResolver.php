<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver;

final class O2SwitchEnvironmentPathResolver
{
    public static function resolveEnvFilePath(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
    ): ?string {
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
            return null;
        }

        return $deployPath->absoluteDeployPath.'/.env';
    }
}
