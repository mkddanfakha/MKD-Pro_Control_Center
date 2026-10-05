<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

final class O2SwitchStorageCommandPlanResolver
{
    /**
     * @return array{plan: ?O2SwitchStorageCommandPlan, code: ?string, message: ?string}
     */
    public static function resolve(
        O2SwitchStorageConfiguration $configuration,
        O2SwitchStorageDeploymentTarget $deploymentTarget,
    ): array {
        /** @var list<string> $directories */
        $directories = config('provisioning.gestion.storage_required_writable_relative_directories', []);

        if ($directories === []) {
            return [
                'plan' => null,
                'code' => 'o2switch_storage_not_configured',
                'message' => 'Profil storage Gestion incomplet.',
            ];
        }

        $requiresLink = (bool) config('provisioning.gestion.storage_requires_storage_link', true);

        /** @var list<string> $linkArgv */
        $linkArgv = config('provisioning.gestion.storage_link_artisan_argv', [
            'php', 'artisan', 'storage:link',
        ]);

        if ($requiresLink && O2SwitchStorageArtisanCommandPolicy::containsForbiddenCacheOrOptimizeCommand($linkArgv)) {
            return [
                'plan' => null,
                'code' => 'o2switch_storage_not_configured',
                'message' => 'Commande storage link invalide.',
            ];
        }

        /** @var array{link_relative: string, target_relative: string}|null $mapping */
        $mapping = null;
        if ($requiresLink) {
            $linkRelative = (string) config('provisioning.gestion.storage_link_relative.link', 'public/storage');
            $targetRelative = (string) config('provisioning.gestion.storage_link_relative.target', 'storage/app/public');
            $mapping = [
                'link_relative' => $linkRelative,
                'target_relative' => $targetRelative,
            ];
        }

        $defaultDisk = (string) config('provisioning.gestion.default_filesystem_disk', 'local');

        /** @var list<string> $disks */
        $disks = config('provisioning.gestion.application_filesystem_disks', ['local', 'public', 'media']);

        $storageFingerprint = hash('sha256', json_encode([
            'directories' => $directories,
            'requires_link' => $requiresLink,
            'deployment_target_fingerprint' => $deploymentTarget->targetFingerprint,
            'default_disk' => $defaultDisk,
        ], JSON_THROW_ON_ERROR));

        return [
            'plan' => new O2SwitchStorageCommandPlan(
                workingDirectory: $deploymentTarget->applicationRootAbsolutePath,
                requiredWritableRelativeDirectories: $directories,
                requiresStorageLink: $requiresLink,
                storageLinkArtisanArgv: $requiresLink ? $linkArgv : [],
                storageLinkMapping: $mapping,
                deploymentTarget: $deploymentTarget,
                storageFingerprint: $storageFingerprint,
                defaultFilesystemDisk: $defaultDisk,
                applicationFilesystemDisks: is_array($disks) ? $disks : [],
            ),
            'code' => null,
            'message' => null,
        ];
    }
}
