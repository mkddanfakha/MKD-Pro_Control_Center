<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

final class O2SwitchMigrateCommandPlan
{
    /**
     * @param  list<string>  $artisanMigrateArgv
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $artisanMigrateArgv,
        public readonly O2SwitchMigrateDatabaseTarget $databaseTarget,
        public readonly string $migrationFingerprint,
    ) {}

    public function safeOperationLabel(): string
    {
        return 'artisan_migrate_force';
    }
}
