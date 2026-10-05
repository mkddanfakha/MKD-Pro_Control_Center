<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Build;

final class O2SwitchBuildConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $deploymentRootBase,
        public readonly string $cpanelHost,
        public readonly string $npmBuildScript,
        public readonly string $artifactManifestRelativePath,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $build */
        $build = config('provisioning.o2switch.build', []);

        /** @var array<string, mixed> $gestion */
        $gestion = config('provisioning.gestion', []);

        return new self(
            provider: (string) ($build['provider'] ?? 'o2switch'),
            enabled: (bool) ($build['enabled'] ?? false),
            dryRun: (bool) ($build['dry_run'] ?? false),
            accountLogicalId: trim((string) ($build['account_logical_id'] ?? '')),
            deploymentRootBase: trim((string) ($build['deployment_root_base'] ?? '')),
            cpanelHost: trim((string) ($build['cpanel_host'] ?? '')),
            npmBuildScript: (string) ($gestion['npm_build_script'] ?? 'build'),
            artifactManifestRelativePath: (string) ($gestion['build_manifest_relative_path'] ?? 'public/build/manifest.json'),
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
            'npm_build_script' => $this->npmBuildScript,
            'artifact_manifest_relative_path' => $this->artifactManifestRelativePath,
            'dry_run' => $this->dryRun,
        ];
    }
}
