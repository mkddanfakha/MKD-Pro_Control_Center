<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Dependencies;

final class O2SwitchDependenciesConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $deploymentRootBase,
        public readonly string $cpanelHost,
        public readonly string $gestionPhpVersionMinimum,
        public readonly bool $nodeDependenciesRequired,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $dependencies */
        $dependencies = config('provisioning.o2switch.dependencies', []);

        /** @var array<string, mixed> $gestion */
        $gestion = config('provisioning.gestion', []);

        return new self(
            provider: (string) ($dependencies['provider'] ?? 'o2switch'),
            enabled: (bool) ($dependencies['enabled'] ?? false),
            dryRun: (bool) ($dependencies['dry_run'] ?? false),
            accountLogicalId: trim((string) ($dependencies['account_logical_id'] ?? '')),
            deploymentRootBase: trim((string) ($dependencies['deployment_root_base'] ?? '')),
            cpanelHost: trim((string) ($dependencies['cpanel_host'] ?? '')),
            gestionPhpVersionMinimum: (string) ($gestion['php_version_minimum'] ?? '8.2'),
            nodeDependenciesRequired: (bool) ($gestion['node_dependencies_required'] ?? true),
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
            'gestion_php_version_minimum' => $this->gestionPhpVersionMinimum,
            'node_dependencies_required' => $this->nodeDependenciesRequired,
            'dry_run' => $this->dryRun,
        ];
    }
}
