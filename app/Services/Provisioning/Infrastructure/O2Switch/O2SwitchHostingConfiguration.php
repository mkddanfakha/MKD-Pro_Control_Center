<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch;

/**
 * Configuration non secrète o2switch hosting (TASK 357).
 */
final class O2SwitchHostingConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $accountLogicalId,
        public readonly string $environment,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $hosting */
        $hosting = config('provisioning.o2switch.hosting', []);

        return new self(
            provider: (string) ($hosting['provider'] ?? 'o2switch'),
            enabled: (bool) ($hosting['enabled'] ?? false),
            dryRun: (bool) ($hosting['dry_run'] ?? false),
            accountLogicalId: trim((string) ($hosting['account_logical_id'] ?? '')),
            environment: (string) ($hosting['environment'] ?? 'production'),
        );
    }

    public function isOperationallyConfigured(): bool
    {
        return $this->accountLogicalId !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function safePublicMetadata(): array
    {
        return [
            'provider' => $this->provider,
            'environment' => $this->environment,
            'account_logical_id' => $this->accountLogicalId !== '' ? $this->accountLogicalId : null,
            'dry_run' => $this->dryRun,
        ];
    }
}
