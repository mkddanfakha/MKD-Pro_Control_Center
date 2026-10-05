<?php

namespace App\Services\Provisioning\Readiness\Verifiers;

use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Readiness\InstallationReadinessProofBuilder;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

final class LocalProvisioningRunReadinessVerifier implements InstallationReadinessProofVerifier
{
    private const SOURCE = 'local_provisioning_run_verifier';

    public function verify(InstallationReadinessVerificationContext $context): array
    {
        $runPresent = $context->provisioningRunId !== null;

        $proofs = [
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_PRESENT,
                $runPresent
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_VERIFIED,
                self::SOURCE,
                $runPresent ? 'provisioning_run_present' : 'provisioning_run_absent',
                $context,
            ),
        ];

        if (! $runPresent) {
            return $proofs;
        }

        $status = $context->provisioningRunStatus;

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_SUCCEEDED,
            $status === ProvisioningRun::STATUS_SUCCEEDED
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_VERIFIED,
            self::SOURCE,
            $status === ProvisioningRun::STATUS_SUCCEEDED
                ? 'provisioning_run_succeeded'
                : 'provisioning_run_not_succeeded',
            $context,
        );

        $terminalFailure = in_array($status, [
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
        ], true);

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_NO_TERMINAL_FAILURE,
            ! $terminalFailure
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::FAILED,
            self::SOURCE,
            $terminalFailure ? 'provisioning_run_terminal_failure' : 'provisioning_run_no_terminal_failure',
            $context,
        );

        return $proofs;
    }
}
