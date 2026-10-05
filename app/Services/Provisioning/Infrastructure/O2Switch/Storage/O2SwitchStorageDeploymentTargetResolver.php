<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver;

final class O2SwitchStorageDeploymentTargetResolver
{
    /**
     * @param  list<string>  $forbiddenAbsolutePathPrefixes
     * @return array{target: ?O2SwitchStorageDeploymentTarget, code: ?string, message: ?string}
     */
    public static function resolve(
        ProvisioningContext $context,
        O2SwitchStorageConfiguration $configuration,
        array $forbiddenAbsolutePathPrefixes,
    ): array {
        if ($context->installation->id !== $context->installationId) {
            return self::invalid('Identifiant installation incohérent dans le contexte.');
        }

        $terminalRunStatuses = [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_CANCELLED,
        ];

        if (in_array($context->provisioningRun->status, $terminalRunStatuses, true)) {
            return self::invalid('Run de provisioning déjà clos — storage non éligible.');
        }

        $overrideInstallationId = $context->externalReferences['storage_target_installation_id']
            ?? $context->externalReferences['target_installation_id']
            ?? null;

        if ($overrideInstallationId !== null && (int) $overrideInstallationId !== $context->installationId) {
            return self::invalid('Référence externe tente de cibler une autre installation.');
        }

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
            return self::invalid('Chemin de déploiement indéterminé.');
        }

        if (! self::relativeSegmentBoundToInstallation($deployPath->relativeSegment, $context->installationId)) {
            return self::invalid('Segment de déploiement non lié à l\'installation courante.');
        }

        foreach (['deploy_relative_path', 'application_root_absolute_path', 'storage_application_root'] as $overrideKey) {
            if (! array_key_exists($overrideKey, $context->externalReferences)) {
                continue;
            }

            $overrideValue = $context->externalReferences[$overrideKey];
            if (! is_string($overrideValue) || trim($overrideValue) === '') {
                continue;
            }

            if ($overrideKey === 'deploy_relative_path') {
                $normalized = str_replace('\\', '/', trim($overrideValue, '/'));
                if ($normalized !== $deployPath->relativeSegment) {
                    return self::invalid('Référence externe incohérente avec le chemin de déploiement.');
                }

                continue;
            }

            if ($overrideKey === 'application_root_absolute_path' || $overrideKey === 'storage_application_root') {
                $normalizedAbsolute = rtrim(str_replace('\\', '/', trim($overrideValue)), '/');
                if ($normalizedAbsolute !== rtrim($deployPath->absoluteDeployPath, '/')) {
                    return self::invalid('Référence externe incohérente avec la racine applicative.');
                }
            }
        }

        $absoluteNormalized = rtrim(str_replace('\\', '/', $deployPath->absoluteDeployPath), '/');

        foreach ($forbiddenAbsolutePathPrefixes as $prefix) {
            if (! is_string($prefix) || trim($prefix) === '') {
                continue;
            }

            $normalizedPrefix = rtrim(str_replace('\\', '/', trim($prefix)), '/');
            if ($absoluteNormalized === $normalizedPrefix
                || str_starts_with($absoluteNormalized.'/', $normalizedPrefix.'/')) {
                return self::invalid('Chemin interdit (Control Center ou liste de sécurité).');
            }
        }

        $fingerprint = hash('sha256', json_encode([
            'installation_id' => $context->installationId,
            'relative_segment' => $deployPath->relativeSegment,
            'application_root' => $absoluteNormalized,
        ], JSON_THROW_ON_ERROR));

        return [
            'target' => new O2SwitchStorageDeploymentTarget(
                installationId: $context->installationId,
                applicationRootAbsolutePath: $absoluteNormalized,
                deployRelativeSegment: $deployPath->relativeSegment,
                targetFingerprint: $fingerprint,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    private static function relativeSegmentBoundToInstallation(string $relative, int $installationId): bool
    {
        $canonical = 'mkd_gestion/installation_'.$installationId;

        if ($relative === $canonical || $relative === 'installation_'.$installationId) {
            return true;
        }

        return preg_match('#(^|/)installation_'.$installationId.'$#', $relative) === 1;
    }

    /**
     * @return array{target: null, code: string, message: string}
     */
    private static function invalid(string $message): array
    {
        return [
            'target' => null,
            'code' => 'o2switch_storage_deployment_path_invalid',
            'message' => $message,
        ];
    }
}
