<?php

namespace App\Services\Provisioning\Infrastructure\Cloudflare;

/**
 * Configuration non secrète Cloudflare DNS (TASK 358).
 */
final class CloudflareDnsConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $enabled,
        public readonly bool $dryRun,
        public readonly string $zoneName,
        public readonly string $zoneId,
        public readonly string $recordType,
    ) {}

    public static function fromApplicationConfig(): self
    {
        /** @var array<string, mixed> $dns */
        $dns = config('provisioning.cloudflare.dns', []);

        return new self(
            provider: (string) ($dns['provider'] ?? 'cloudflare'),
            enabled: (bool) ($dns['enabled'] ?? false),
            dryRun: (bool) ($dns['dry_run'] ?? false),
            zoneName: trim((string) ($dns['zone_name'] ?? '')),
            zoneId: trim((string) ($dns['zone_id'] ?? '')),
            recordType: (string) ($dns['record_type'] ?? 'CNAME'),
        );
    }

    public function hasZoneReference(): bool
    {
        return $this->zoneName !== '' || $this->zoneId !== '';
    }

    public function isDryRunReady(): bool
    {
        return $this->hasZoneReference();
    }

    public function isLiveReady(): bool
    {
        return $this->hasZoneReference() && $this->apiToken() !== null;
    }

    public function apiToken(): ?string
    {
        $token = config('provisioning.secrets.cloudflare_api_token');

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token !== '' ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function safePublicMetadata(): array
    {
        return [
            'provider' => $this->provider,
            'zone_name' => $this->zoneName !== '' ? $this->zoneName : null,
            'zone_id' => $this->zoneId !== '' ? $this->zoneId : null,
            'record_type' => $this->recordType,
            'dry_run' => $this->dryRun,
        ];
    }
}
