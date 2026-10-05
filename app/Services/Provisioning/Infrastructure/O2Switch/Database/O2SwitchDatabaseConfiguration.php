<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Database;

/**
 * Configuration non secrète — base MySQL o2switch (TASK 359).
 */
final class O2SwitchDatabaseConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $databaseNamePrefix,
        public readonly string $mysqlHostLogical,
        public readonly string $cpanelHost,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $database */
        $database = config('provisioning.o2switch.database', []);

        return new self(
            provider: (string) ($database['provider'] ?? 'o2switch'),
            enabled: (bool) ($database['enabled'] ?? false),
            dryRun: (bool) ($database['dry_run'] ?? false),
            accountLogicalId: trim((string) ($database['account_logical_id'] ?? '')),
            databaseNamePrefix: (string) ($database['database_name_prefix'] ?? ''),
            mysqlHostLogical: (string) ($database['mysql_host_logical'] ?? 'localhost'),
            cpanelHost: trim((string) ($database['cpanel_host'] ?? '')),
        );
    }

    public function isNamingReady(): bool
    {
        return $this->databaseNamePrefix !== '';
    }

    public function isDryRunReady(): bool
    {
        return $this->accountLogicalId !== '' && $this->isNamingReady();
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
            'database_name_prefix' => $this->databaseNamePrefix !== '' ? $this->databaseNamePrefix : null,
            'mysql_host_logical' => $this->mysqlHostLogical,
            'cpanel_host' => $this->cpanelHost !== '' ? $this->cpanelHost : null,
            'dry_run' => $this->dryRun,
        ];
    }
}
