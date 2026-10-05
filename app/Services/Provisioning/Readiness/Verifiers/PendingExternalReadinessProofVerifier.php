<?php

namespace App\Services\Provisioning\Readiness\Verifiers;

use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Services\Provisioning\Readiness\InstallationReadinessProofBuilder;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

/**
 * Placeholder explicite pour preuves externes non simulées (TASK 381).
 */
final class PendingExternalReadinessProofVerifier implements InstallationReadinessProofVerifier
{
    private const SOURCE = 'pending_external_verifier';

    public function verify(InstallationReadinessVerificationContext $context): array
    {
        $proofs = [];

        foreach (InstallationReadinessProofCatalog::externalNotYetAutomatedProofCodes() as $code) {
            $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
                $code,
                InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
                self::SOURCE,
                'not_yet_automated',
                $context,
            );
        }

        return $proofs;
    }
}
