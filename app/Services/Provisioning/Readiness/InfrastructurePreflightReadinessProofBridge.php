<?php

namespace App\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightReport;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Support\Provisioning\InfrastructurePreflightReadinessStateMapper;
use App\Support\Provisioning\InstallationReadinessProofCatalog;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Transforme un rapport de préflight déjà calculé en preuves readiness (TASK 382).
 *
 * Ne lance pas le préflight, n'appelle pas le réseau, ne persiste rien.
 */
final class InfrastructurePreflightReadinessProofBridge
{
    public const PROOF_SOURCE = 'infrastructure_preflight';

    /**
     * @return list<InstallationReadinessProof>
     */
    public function transform(
        InfrastructurePreflightReport $report,
        ?DateTimeInterface $verifiedAt = null,
    ): array {
        $verifiedAt ??= new DateTimeImmutable;
        $context = $this->minimalContext($verifiedAt);
        $proofs = [];

        foreach (InstallationReadinessProofCatalog::preflightMandatoryServiceKeys() as $serviceKey) {
            $check = $this->findCheck($report->checks, $serviceKey);
            $proofCode = InstallationReadinessProofCatalog::proofCodeForPreflightServiceKey($serviceKey);

            if ($check === null) {
                $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
                    $proofCode,
                    InstallationReadinessProofStatus::NOT_VERIFIED,
                    self::PROOF_SOURCE,
                    $this->safeSummary($serviceKey, 'missing', 'preflight_check_absent'),
                    $context,
                );

                continue;
            }

            $proofStatus = InfrastructurePreflightReadinessStateMapper::toProofStatus($check->state);

            $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
                $proofCode,
                $proofStatus,
                self::PROOF_SOURCE,
                $this->safeSummary($serviceKey, $check->state, $check->code),
                $context,
                $proofStatus === InstallationReadinessProofStatus::VERIFIED ? $verifiedAt : null,
            );
        }

        return $proofs;
    }

    /**
     * @param  list<InfrastructurePreflightCheckResult>  $checks
     */
    private function findCheck(array $checks, string $serviceKey): ?InfrastructurePreflightCheckResult
    {
        foreach ($checks as $check) {
            if ($check->serviceKey === $serviceKey) {
                return $check;
            }
        }

        return null;
    }

    private function safeSummary(string $serviceKey, string $preflightState, string $diagnosticCode): string
    {
        $diagnosticCode = preg_replace('/[^a-z0-9_\-]/', '_', strtolower($diagnosticCode)) ?: 'unknown';
        $summary = sprintf(
            'infrastructure_preflight;service=%s;preflight_state=%s;diagnostic_code=%s',
            $serviceKey,
            $preflightState,
            $diagnosticCode,
        );

        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage($summary);

        return $summary;
    }

    private function minimalContext(DateTimeInterface $verifiedAt): InstallationReadinessVerificationContext
    {
        return new InstallationReadinessVerificationContext(
            installationId: null,
            installationStatus: null,
            installationTerminated: false,
            clientRecordPresent: false,
            subdomain: null,
            domain: null,
            installationVersion: null,
            databaseName: null,
            databaseHost: null,
            provisioningRunId: null,
            provisioningRunStatus: null,
            targetVersion: null,
            targetCommit: null,
            runSteps: [],
            provisioningConfigFlags: [],
            verifiedAt: $verifiedAt,
        );
    }
}
