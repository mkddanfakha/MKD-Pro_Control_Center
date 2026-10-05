<?php

namespace App\Contracts\Provisioning\Readiness;

use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;

/**
 * Vérificateur sans effet de bord produisant des preuves readiness (TASK 381).
 */
interface InstallationReadinessProofVerifier
{
    /**
     * @return list<InstallationReadinessProof>
     */
    public function verify(InstallationReadinessVerificationContext $context): array;
}
