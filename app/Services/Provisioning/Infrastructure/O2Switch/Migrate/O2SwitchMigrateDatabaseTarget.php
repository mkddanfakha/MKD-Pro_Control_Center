<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

/**
 * Cible MySQL explicitement liée à une Installation — jamais de mot de passe.
 */
final class O2SwitchMigrateDatabaseTarget
{
    public function __construct(
        public readonly int $installationId,
        public readonly string $databaseName,
        public readonly string $databaseHost,
        public readonly string $targetFingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function safePublicMetadata(): array
    {
        return [
            'migration_target_installation_id' => $this->installationId,
            'migration_target_database_name' => $this->databaseName,
            'migration_target_database_host' => $this->databaseHost,
            'migration_target_fingerprint' => $this->targetFingerprint,
        ];
    }
}
