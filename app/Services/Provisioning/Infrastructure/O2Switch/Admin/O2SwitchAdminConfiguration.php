<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

final class O2SwitchAdminConfiguration
{
    /**
     * @param  list<string>  $forbiddenAbsolutePathPrefixes
     */
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $deploymentRootBase,
        public readonly string $cpanelHost,
        public readonly array $forbiddenAbsolutePathPrefixes,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $admin */
        $admin = config('provisioning.o2switch.admin', []);

        /** @var list<string> $forbidden */
        $forbidden = config('provisioning.security.forbidden_storage_absolute_path_prefixes', []);

        return new self(
            provider: (string) ($admin['provider'] ?? 'o2switch'),
            enabled: (bool) ($admin['enabled'] ?? false),
            dryRun: (bool) ($admin['dry_run'] ?? false),
            accountLogicalId: trim((string) ($admin['account_logical_id'] ?? '')),
            deploymentRootBase: trim((string) ($admin['deployment_root_base'] ?? '')),
            cpanelHost: trim((string) ($admin['cpanel_host'] ?? '')),
            forbiddenAbsolutePathPrefixes: is_array($forbidden) ? $forbidden : [],
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
