<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

/**
 * Racine applicative Gestion distante liée à une Installation.
 */
final class O2SwitchStorageDeploymentTarget
{
    public function __construct(
        public readonly int $installationId,
        public readonly string $applicationRootAbsolutePath,
        public readonly string $deployRelativeSegment,
        public readonly string $targetFingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function safePublicMetadata(): array
    {
        return [
            'storage_target_installation_id' => $this->installationId,
            'deploy_relative_segment' => $this->deployRelativeSegment,
            'storage_target_fingerprint' => $this->targetFingerprint,
        ];
    }
}
