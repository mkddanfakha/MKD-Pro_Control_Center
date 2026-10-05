<?php

namespace App\DTO\Provisioning\Readiness;

use DateTimeInterface;

/**
 * Contexte de vérification readiness — aucun secret (TASK 381).
 */
final class InstallationReadinessVerificationContext
{
    /**
     * @param  list<InstallationReadinessRunStepSnapshot>  $runSteps
     * @param  array<string, bool|int|string|null>  $provisioningConfigFlags  Valeurs non sensibles uniquement
     */
    public function __construct(
        public readonly ?int $installationId,
        public readonly ?string $installationStatus,
        public readonly bool $installationTerminated,
        public readonly bool $clientRecordPresent,
        public readonly ?string $subdomain,
        public readonly ?string $domain,
        public readonly ?string $installationVersion,
        public readonly ?string $databaseName,
        public readonly ?string $databaseHost,
        public readonly ?int $provisioningRunId,
        public readonly ?string $provisioningRunStatus,
        public readonly ?string $targetVersion,
        public readonly ?string $targetCommit,
        public readonly array $runSteps,
        public readonly array $provisioningConfigFlags,
        public readonly DateTimeInterface $verifiedAt,
    ) {}

    public function databaseConfigurationPresent(): bool
    {
        return trim((string) $this->databaseName) !== ''
            && trim((string) $this->databaseHost) !== '';
    }

    public function installationStatusEligibleForProvisioning(): bool
    {
        if ($this->installationTerminated) {
            return false;
        }

        $status = strtolower(trim((string) $this->installationStatus));

        return in_array($status, ['active', 'suspended'], true);
    }

    public function subdomainPresent(): bool
    {
        return trim((string) $this->subdomain) !== '';
    }

    public function domainPresent(): bool
    {
        return trim((string) $this->domain) !== '';
    }
}
