<?php

namespace App\DTO\Provisioning;

use App\Support\Provisioning\ProvisioningSecretSanitizer;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Preuve unitaire de readiness — résumé toujours sanitizé (TASK 380).
 */
final class InstallationReadinessProof
{
    public readonly bool $blockingForReady;

    public readonly ?DateTimeInterface $verifiedAt;

    public function __construct(
        public readonly string $domain,
        public readonly string $code,
        public readonly string $level,
        public readonly string $status,
        public readonly string $source,
        public readonly bool $automaticallyVerifiable,
        public readonly bool $persistable,
        public readonly string $safeSummary,
        ?DateTimeInterface $verifiedAt = null,
        ?bool $blockingForReady = null,
    ) {
        if (! InstallationReadinessProofLevel::isValid($level)) {
            throw new InvalidArgumentException('Niveau de preuve inconnu : '.$level);
        }

        if (! InstallationReadinessProofStatus::isValid($status)) {
            throw new InvalidArgumentException('Statut de preuve inconnu : '.$status);
        }

        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage($safeSummary);

        $this->verifiedAt = $verifiedAt;
        $this->blockingForReady = $blockingForReady ?? self::defaultBlockingForReady($level, $status);
    }

    public static function defaultBlockingForReady(string $level, string $status): bool
    {
        if (! InstallationReadinessProofLevel::preventsReadyWhenUnmet($level)) {
            return false;
        }

        return InstallationReadinessProofStatus::blocksRequiredReady($status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'domain' => $this->domain,
            'code' => $this->code,
            'level' => $this->level,
            'status' => $this->status,
            'blocking_for_ready' => $this->blockingForReady,
            'source' => $this->source,
            'automatically_verifiable' => $this->automaticallyVerifiable,
            'persistable' => $this->persistable,
            'verified_at' => $this->verifiedAt?->format(DATE_ATOM),
            'safe_summary' => $this->safeSummary,
        ], fn ($value) => $value !== null);
    }
}
