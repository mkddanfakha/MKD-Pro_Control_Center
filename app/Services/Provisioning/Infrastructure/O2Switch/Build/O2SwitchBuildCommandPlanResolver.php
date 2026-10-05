<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Build;

use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver;

final class O2SwitchBuildCommandPlanResolver
{
    /**
     * @return array{plan: ?O2SwitchBuildCommandPlan, code: ?string, message: ?string}
     */
    public static function resolve(
        ProvisioningContext $context,
        O2SwitchBuildConfiguration $configuration,
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
                'code' => 'o2switch_build_not_configured',
                'message' => 'Répertoire applicatif Gestion indéterminé.',
            ];
        }

        $npmBuildArgv = ['npm', 'run', $configuration->npmBuildScript];

        $buildFingerprint = hash('sha256', json_encode([
            'package-lock.json',
            $configuration->npmBuildScript,
            $configuration->artifactManifestRelativePath,
            $context->targetCommit,
            $context->targetVersion,
        ], JSON_THROW_ON_ERROR));

        return [
            'plan' => new O2SwitchBuildCommandPlan(
                workingDirectory: $deployPath->absoluteDeployPath,
                npmBuildArgv: $npmBuildArgv,
                expectedArtifactRelativePaths: [
                    $configuration->artifactManifestRelativePath,
                    'public/build',
                ],
                buildFingerprint: $buildFingerprint,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * Variables VITE_* publiques attendues sur l'installation distante (`.env` Gestion).
     *
     * @return list<string>
     */
    public static function missingRequiredPublicViteKeys(ProvisioningContext $context): array
    {
        /** @var list<string> $required */
        $required = config('provisioning.gestion.build_required_vite_keys', ['VITE_APP_NAME']);

        $provided = $context->externalReferences['vite_public_env_keys_present'] ?? null;
        if (! is_array($provided)) {
            return $required;
        }

        $missing = [];
        foreach ($required as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (ProvisioningContext::isForbiddenSecretKey($key)) {
                continue;
            }

            if (! in_array($key, $provided, true)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
