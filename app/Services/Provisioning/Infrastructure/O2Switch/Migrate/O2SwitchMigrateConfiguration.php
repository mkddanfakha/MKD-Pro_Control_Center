<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

final class O2SwitchMigrateConfiguration
{
    /**
     * @param  list<string>  $forbiddenMigrationDatabaseNames
     */
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $deploymentRootBase,
        public readonly string $cpanelHost,
        public readonly array $forbiddenMigrationDatabaseNames,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $migrate */
        $migrate = config('provisioning.o2switch.migrate', []);

        /** @var list<string> $forbidden */
        $forbidden = config('provisioning.security.forbidden_migration_database_names', []);

        return new self(
            provider: (string) ($migrate['provider'] ?? 'o2switch'),
            enabled: (bool) ($migrate['enabled'] ?? false),
            dryRun: (bool) ($migrate['dry_run'] ?? false),
            accountLogicalId: trim((string) ($migrate['account_logical_id'] ?? '')),
            deploymentRootBase: trim((string) ($migrate['deployment_root_base'] ?? '')),
            cpanelHost: trim((string) ($migrate['cpanel_host'] ?? '')),
            forbiddenMigrationDatabaseNames: is_array($forbidden) ? $forbidden : [],
        );
    }

    public function isDryRunReady(): bool
    {
        return $this->accountLogicalId !== '' && $this->deploymentRootBase !== '';
    }

    public function isLiveReady(): bool
    {
        if (! $this->isDryRunReady()) {
            return false;
        }

        if ($this->cpanelHost === '') {
            return false;
        }

        $token = config('provisioning.secrets.o2switch_api_token');
        $cpanelUser = config('provisioning.secrets.o2switch_cpanel_username');

        return is_string($token) && $token !== ''
            && is_string($cpanelUser) && $cpanelUser !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function safePublicMetadata(): array
    {
        return [
            'provider' => $this->provider,
            'account_logical_id' => $this->accountLogicalId !== '' ? $this->accountLogicalId : null,
            'deployment_root_base' => $this->deploymentRootBase !== '' ? $this->deploymentRootBase : null,
            'cpanel_host' => $this->cpanelHost !== '' ? $this->cpanelHost : null,
            'dry_run' => $this->dryRun,
        ];
    }
}
