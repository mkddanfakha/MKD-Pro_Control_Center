<?php

namespace App\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Support\Provisioning\InstallationReadinessProofCatalog;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Construit des preuves à partir du catalogue (TASK 381).
 */
final class InstallationReadinessProofBuilder
{
    public static function fromCatalog(
        string $code,
        string $status,
        string $source,
        string $safeSummary,
        InstallationReadinessVerificationContext $context,
        ?DateTimeInterface $verifiedAt = null,
    ): InstallationReadinessProof {
        $definition = InstallationReadinessProofCatalog::definition($code);
        if ($definition === null) {
            throw new InvalidArgumentException('Code de preuve inconnu : '.$code);
        }

        $verifiedAt ??= $context->verifiedAt;

        return new InstallationReadinessProof(
            domain: $definition['domain'],
            code: $code,
            level: $definition['level'],
            status: $status,
            source: $source,
            automaticallyVerifiable: $definition['automatically_verifiable'],
            persistable: true,
            safeSummary: $safeSummary,
            verifiedAt: $status === InstallationReadinessProofStatus::VERIFIED ? $verifiedAt : null,
        );
    }
}
