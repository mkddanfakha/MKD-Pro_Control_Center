<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

/**
 * Configuration non secrète — déploiement Gestion o2switch (TASK 360).
 */
final class O2SwitchDeployConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $deploymentRootBase,
        public readonly string $gestionGitRepositoryUrl,
        public readonly string $defaultGitRef,
        public readonly string $cpanelHost,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $deploy */
        $deploy = config('provisioning.o2switch.deploy', []);

        return new self(
            provider: (string) ($deploy['provider'] ?? 'o2switch'),
            enabled: (bool) ($deploy['enabled'] ?? false),
            dryRun: (bool) ($deploy['dry_run'] ?? false),
            accountLogicalId: trim((string) ($deploy['account_logical_id'] ?? '')),
            deploymentRootBase: trim((string) ($deploy['deployment_root_base'] ?? '')),
            gestionGitRepositoryUrl: trim((string) ($deploy['gestion_git_repository_url'] ?? '')),
            defaultGitRef: trim((string) ($deploy['default_git_ref'] ?? '')),
            cpanelHost: trim((string) ($deploy['cpanel_host'] ?? '')),
        );
    }

    public function isDryRunReady(): bool
    {
        return $this->accountLogicalId !== ''
            && $this->deploymentRootBase !== ''
            && $this->gestionGitRepositoryUrl !== '';
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
            'gestion_git_repository_url' => $this->gestionGitRepositoryUrl !== '' ? $this->gestionGitRepositoryUrl : null,
            'default_git_ref' => $this->defaultGitRef !== '' ? $this->defaultGitRef : null,
            'cpanel_host' => $this->cpanelHost !== '' ? $this->cpanelHost : null,
            'dry_run' => $this->dryRun,
        ];
    }
}
