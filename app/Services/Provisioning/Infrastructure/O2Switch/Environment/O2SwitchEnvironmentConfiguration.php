<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

final class O2SwitchEnvironmentConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $deploymentRootBase,
        public readonly string $gestionAppBaseDomain,
        public readonly string $applicationEnv,
        public readonly bool $applicationDebug,
        public readonly string $cpanelHost,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $environment */
        $environment = config('provisioning.o2switch.environment', []);

        return new self(
            provider: (string) ($environment['provider'] ?? 'o2switch'),
            enabled: (bool) ($environment['enabled'] ?? false),
            dryRun: (bool) ($environment['dry_run'] ?? false),
            accountLogicalId: trim((string) ($environment['account_logical_id'] ?? '')),
            deploymentRootBase: trim((string) ($environment['deployment_root_base'] ?? '')),
            gestionAppBaseDomain: trim((string) ($environment['gestion_app_base_domain'] ?? '')),
            applicationEnv: (string) ($environment['application_env'] ?? 'production'),
            applicationDebug: (bool) ($environment['application_debug'] ?? false),
            cpanelHost: trim((string) ($environment['cpanel_host'] ?? '')),
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
            'gestion_app_base_domain' => $this->gestionAppBaseDomain !== '' ? $this->gestionAppBaseDomain : null,
            'application_env' => $this->applicationEnv,
            'application_debug' => $this->applicationDebug,
            'cpanel_host' => $this->cpanelHost !== '' ? $this->cpanelHost : null,
            'dry_run' => $this->dryRun,
        ];
    }
}
