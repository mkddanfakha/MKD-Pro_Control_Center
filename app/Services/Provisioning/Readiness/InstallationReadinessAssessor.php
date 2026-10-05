<?php

namespace App\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InstallationReadinessAssessmentResult;
use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\InstallationReadinessProofLevel;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Agrège des preuves et calcule une décision readiness sans persistance (TASK 380).
 */
final class InstallationReadinessAssessor
{
    /**
     * @param  list<InstallationReadinessProof>  $proofs
     * @param  list<string>  $requiredProofCodes  Codes REQUIRED attendus (absence = NOT_READY)
     */
    public function assess(
        array $proofs,
        array $requiredProofCodes = [],
        ?DateTimeInterface $calculatedAt = null,
    ): InstallationReadinessAssessmentResult {
        $calculatedAt ??= new DateTimeImmutable;

        /** @var array<string, InstallationReadinessProof> $byCode */
        $byCode = [];
        foreach ($proofs as $proof) {
            $byCode[$proof->code] = $proof;
        }

        $missingRequired = [];
        foreach ($requiredProofCodes as $code) {
            if (! isset($byCode[$code])) {
                $missingRequired[] = $code;
            }
        }

        $failedRequired = [];
        $manualIntervention = [];
        $requiredIncomplete = [];

        foreach ($requiredProofCodes as $code) {
            if (! isset($byCode[$code])) {
                continue;
            }

            $this->classifyRequiredBlocker($byCode[$code], $failedRequired, $manualIntervention, $requiredIncomplete);
        }

        foreach ($proofs as $proof) {
            if ($proof->level !== InstallationReadinessProofLevel::REQUIRED) {
                continue;
            }

            if ($requiredProofCodes !== [] && in_array($proof->code, $requiredProofCodes, true)) {
                continue;
            }

            $this->classifyRequiredBlocker($proof, $failedRequired, $manualIntervention, $requiredIncomplete);
        }

        $recommendedWarnings = [];
        $optionalProofs = [];
        $outsideProofs = [];

        foreach ($proofs as $proof) {
            match ($proof->level) {
                InstallationReadinessProofLevel::RECOMMENDED => $this->collectRecommended($proof, $recommendedWarnings),
                InstallationReadinessProofLevel::OPTIONAL => $optionalProofs[] = $proof,
                InstallationReadinessProofLevel::OUTSIDE_PROVISIONING => $outsideProofs[] = $proof,
                default => null,
            };
        }

        $outcome = $this->resolveOutcome($missingRequired, $failedRequired, $manualIntervention, $requiredIncomplete);

        return new InstallationReadinessAssessmentResult(
            outcome: $outcome,
            missingRequiredProofCodes: $missingRequired,
            failedRequiredProofs: $failedRequired,
            manualInterventionRequiredProofs: $manualIntervention,
            recommendedWarnings: $recommendedWarnings,
            optionalProofs: $optionalProofs,
            outsideProvisioningProofs: $outsideProofs,
            safeSummary: $this->buildSafeSummary($outcome, $missingRequired, $failedRequired, $manualIntervention, $requiredIncomplete, $recommendedWarnings),
            calculatedAt: $calculatedAt,
        );
    }

    /**
     * @param  list<InstallationReadinessProof>  $failedRequired
     * @param  list<InstallationReadinessProof>  $manualIntervention
     * @param  list<InstallationReadinessProof>  $requiredIncomplete
     */
    private function classifyRequiredBlocker(
        InstallationReadinessProof $proof,
        array &$failedRequired,
        array &$manualIntervention,
        array &$requiredIncomplete,
    ): void {
        if ($proof->status === InstallationReadinessProofStatus::VERIFIED) {
            return;
        }

        if ($proof->status === InstallationReadinessProofStatus::FAILED) {
            $failedRequired[] = $proof;

            return;
        }

        if ($proof->status === InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED) {
            $manualIntervention[] = $proof;

            return;
        }

        $requiredIncomplete[] = $proof;
    }

    /**
     * @param  list<string>  $missingRequired
     * @param  list<InstallationReadinessProof>  $failedRequired
     * @param  list<InstallationReadinessProof>  $manualIntervention
     * @param  list<InstallationReadinessProof>  $requiredIncomplete
     */
    private function resolveOutcome(
        array $missingRequired,
        array $failedRequired,
        array $manualIntervention,
        array $requiredIncomplete,
    ): string {
        if ($failedRequired !== []) {
            return InstallationReadinessDecisionOutcome::FAILED;
        }

        if ($manualIntervention !== []) {
            return InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED;
        }

        if ($missingRequired !== [] || $requiredIncomplete !== []) {
            return InstallationReadinessDecisionOutcome::NOT_READY;
        }

        return InstallationReadinessDecisionOutcome::READY;
    }

    /**
     * @param  list<InstallationReadinessProof>  $recommendedWarnings
     */
    private function collectRecommended(InstallationReadinessProof $proof, array &$recommendedWarnings): void
    {
        if ($proof->status !== InstallationReadinessProofStatus::VERIFIED) {
            $recommendedWarnings[] = $proof;
        }
    }

    /**
     * @param  list<string>  $missingRequired
     * @param  list<InstallationReadinessProof>  $failedRequired
     * @param  list<InstallationReadinessProof>  $manualIntervention
     * @param  list<InstallationReadinessProof>  $requiredIncomplete
     * @param  list<InstallationReadinessProof>  $recommendedWarnings
     */
    private function buildSafeSummary(
        string $outcome,
        array $missingRequired,
        array $failedRequired,
        array $manualIntervention,
        array $requiredIncomplete,
        array $recommendedWarnings,
    ): string {
        $parts = ['readiness_outcome='.$outcome];

        if ($missingRequired !== []) {
            $parts[] = 'missing_required='.count($missingRequired);
        }

        if ($failedRequired !== []) {
            $parts[] = 'failed_required='.count($failedRequired);
        }

        if ($manualIntervention !== []) {
            $parts[] = 'manual_intervention='.count($manualIntervention);
        }

        if ($requiredIncomplete !== []) {
            $parts[] = 'required_incomplete='.count($requiredIncomplete);
        }

        if ($recommendedWarnings !== []) {
            $parts[] = 'recommended_warnings='.count($recommendedWarnings);
        }

        return implode('; ', $parts);
    }
}
