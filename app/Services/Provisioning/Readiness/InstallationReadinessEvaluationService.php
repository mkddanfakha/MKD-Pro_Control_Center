<?php

namespace App\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InfrastructurePreflightReport;
use App\DTO\Provisioning\InstallationReadinessAssessmentResult;
use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\ProvisioningInfrastructurePreflight;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

/**
 * Orchestration read-only : collecte de preuves + décision readiness (TASK 3W).
 */
class InstallationReadinessEvaluationService
{
    public function __construct(
        private readonly InstallationReadinessVerificationContextFactory $contextFactory,
        private readonly InstallationReadinessCompositeProofCollector $proofCollector,
        private readonly InstallationReadinessAssessor $assessor,
        private readonly ProvisioningInfrastructurePreflight $infrastructurePreflight,
    ) {}

    public function evaluateInstallation(
        Installation $installation,
        ?ProvisioningRun $provisioningRun = null,
        ?InfrastructurePreflightReport $preflightReport = null,
        bool $runPreflightWhenMissing = true,
    ): InstallationReadinessAssessmentResult {
        $installation->loadMissing('client');
        $provisioningRun?->loadMissing('steps');

        $context = $this->contextFactory->fromInstallationAndRun($installation, $provisioningRun);
        $resolvedPreflight = $this->resolvePreflightReport($preflightReport, $runPreflightWhenMissing);

        return $this->assessCollectedProofs(
            $this->proofCollector->collect($context, $resolvedPreflight),
        );
    }

    public function evaluateProvisioningRun(
        ProvisioningRun $provisioningRun,
        ?InfrastructurePreflightReport $preflightReport = null,
        bool $runPreflightWhenMissing = true,
    ): InstallationReadinessAssessmentResult {
        $provisioningRun->loadMissing(['installation.client', 'steps']);

        if ($provisioningRun->installation === null) {
            throw new \InvalidArgumentException('ProvisioningRun sans installation associée.');
        }

        return $this->evaluateInstallation(
            $provisioningRun->installation,
            $provisioningRun,
            $preflightReport,
            $runPreflightWhenMissing,
        );
    }

    public function evaluateProvisioningContext(
        ProvisioningContext $context,
        ?InfrastructurePreflightReport $preflightReport = null,
        bool $runPreflightWhenMissing = false,
    ): InstallationReadinessAssessmentResult {
        return $this->evaluateInstallation(
            $context->installation,
            $context->provisioningRun,
            $preflightReport,
            $runPreflightWhenMissing,
        );
    }

    /**
     * @param  list<InstallationReadinessProof>  $proofs
     */
    public function assessCollectedProofs(array $proofs): InstallationReadinessAssessmentResult
    {
        return $this->assessor->assess(
            $proofs,
            InstallationReadinessProofCatalog::requiredProofCodesForProvisioningExecution(),
        );
    }

    public function allowsPipelineExecution(InstallationReadinessAssessmentResult $assessment): bool
    {
        return $assessment->outcome === InstallationReadinessDecisionOutcome::READY;
    }

    public function executionBlockReason(InstallationReadinessAssessmentResult $assessment): ?string
    {
        if ($this->allowsPipelineExecution($assessment)) {
            return null;
        }

        if ($assessment->failedRequiredProofs !== []) {
            return $this->formatProofBlockReason(
                'Preuve bloquante',
                $assessment->failedRequiredProofs[0],
            );
        }

        if ($assessment->manualInterventionRequiredProofs !== []) {
            return $this->formatProofBlockReason(
                'Intervention manuelle requise',
                $assessment->manualInterventionRequiredProofs[0],
            );
        }

        if ($assessment->missingRequiredProofCodes !== []) {
            return sprintf(
                'Preuves obligatoires manquantes (%d).',
                count($assessment->missingRequiredProofCodes),
            );
        }

        return 'Installation non prête pour le provisioning.';
    }

    private function formatProofBlockReason(string $prefix, InstallationReadinessProof $proof): string
    {
        return sprintf('%s : %s (%s).', $prefix, $this->humanProofLabel($proof->code), $proof->safeSummary);
    }

    private function humanProofLabel(string $code): string
    {
        return str_replace('_', ' ', $code);
    }

    private function resolvePreflightReport(
        ?InfrastructurePreflightReport $preflightReport,
        bool $runPreflightWhenMissing,
    ): ?InfrastructurePreflightReport {
        if ($preflightReport !== null) {
            return $preflightReport;
        }

        if (! $runPreflightWhenMissing) {
            return null;
        }

        return $this->infrastructurePreflight->run();
    }
}
