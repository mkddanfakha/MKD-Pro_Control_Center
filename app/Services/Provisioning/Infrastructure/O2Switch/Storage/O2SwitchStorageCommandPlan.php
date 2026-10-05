<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

final class O2SwitchStorageCommandPlan
{
    /**
     * @param  list<string>  $requiredWritableRelativeDirectories
     * @param  list<string>  $storageLinkArtisanArgv
     * @param  array{link_relative: string, target_relative: string}|null  $storageLinkMapping
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $requiredWritableRelativeDirectories,
        public readonly bool $requiresStorageLink,
        public readonly array $storageLinkArtisanArgv,
        public readonly ?array $storageLinkMapping,
        public readonly O2SwitchStorageDeploymentTarget $deploymentTarget,
        public readonly string $storageFingerprint,
        public readonly string $defaultFilesystemDisk,
        /** @var list<string> */
        public readonly array $applicationFilesystemDisks,
    ) {}

    public function safeOperationLabel(): string
    {
        if ($this->requiresStorageLink) {
            return 'storage_directories_and_storage_link';
        }

        return 'storage_directories_only';
    }
}
