<?php

namespace App\Services\Provisioning\Readiness;

use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Services\Provisioning\Readiness\Verifiers\CapacityReservationReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalInstallationReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalProvisioningConfigurationReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalProvisioningRunReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalProvisioningStepsReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\PendingExternalReadinessProofVerifier;

/**
 * Chaîne locale : verifiers → preuves (TASK 381).
 */
final class InstallationReadinessLocalProofCollector
{
    /**
     * @param  list<InstallationReadinessProofVerifier>|null  $verifiers
     */
    public function __construct(
        private readonly ?array $verifiers = null,
    ) {}

    /**
     * @return list<InstallationReadinessProof>
     */
    public function collect(InstallationReadinessVerificationContext $context): array
    {
        $proofs = [];

        foreach ($this->resolvedVerifiers() as $verifier) {
            foreach ($verifier->verify($context) as $proof) {
                $proofs[$proof->code] = $proof;
            }
        }

        return array_values($proofs);
    }

    /**
     * @return list<InstallationReadinessProofVerifier>
     */
    private function resolvedVerifiers(): array
    {
        return $this->verifiers ?? [
            new LocalInstallationReadinessVerifier,
            new LocalProvisioningRunReadinessVerifier,
            new LocalProvisioningStepsReadinessVerifier,
            new LocalProvisioningConfigurationReadinessVerifier,
            new PendingExternalReadinessProofVerifier,
            app(CapacityReservationReadinessVerifier::class),
        ];
    }
}
