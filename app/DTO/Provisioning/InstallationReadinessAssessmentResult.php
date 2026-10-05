<?php

namespace App\DTO\Provisioning;

use App\Support\Provisioning\ProvisioningSecretSanitizer;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Synthèse calculée de readiness — sans écriture DB (TASK 380).
 */
final class InstallationReadinessAssessmentResult
{
    /**
     * @param  list<string>  $missingRequiredProofCodes
     * @param  list<InstallationReadinessProof>  $failedRequiredProofs
     * @param  list<InstallationReadinessProof>  $manualInterventionRequiredProofs
     * @param  list<InstallationReadinessProof>  $recommendedWarnings
     * @param  list<InstallationReadinessProof>  $optionalProofs
     * @param  list<InstallationReadinessProof>  $outsideProvisioningProofs
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $missingRequiredProofCodes,
        public readonly array $failedRequiredProofs,
        public readonly array $manualInterventionRequiredProofs,
        public readonly array $recommendedWarnings,
        public readonly array $optionalProofs,
        public readonly array $outsideProvisioningProofs,
        public readonly string $safeSummary,
        public readonly DateTimeInterface $calculatedAt,
    ) {
        if (! InstallationReadinessDecisionOutcome::isValid($outcome)) {
            throw new InvalidArgumentException('Résultat readiness inconnu : '.$outcome);
        }

        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage($safeSummary);

        foreach ([
            ...$failedRequiredProofs,
            ...$manualInterventionRequiredProofs,
            ...$recommendedWarnings,
            ...$optionalProofs,
            ...$outsideProvisioningProofs,
        ] as $proof) {
            if (! $proof instanceof InstallationReadinessProof) {
                throw new InvalidArgumentException('Preuve invalide dans le résultat readiness.');
            }
        }
    }

    public function isReady(): bool
    {
        return $this->outcome === InstallationReadinessDecisionOutcome::READY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'missing_required_proof_codes' => $this->missingRequiredProofCodes,
            'failed_required_proofs' => array_map(
                static fn (InstallationReadinessProof $proof) => $proof->toArray(),
                $this->failedRequiredProofs,
            ),
            'manual_intervention_required_proofs' => array_map(
                static fn (InstallationReadinessProof $proof) => $proof->toArray(),
                $this->manualInterventionRequiredProofs,
            ),
            'recommended_warnings' => array_map(
                static fn (InstallationReadinessProof $proof) => $proof->toArray(),
                $this->recommendedWarnings,
            ),
            'optional_proofs' => array_map(
                static fn (InstallationReadinessProof $proof) => $proof->toArray(),
                $this->optionalProofs,
            ),
            'outside_provisioning_proofs' => array_map(
                static fn (InstallationReadinessProof $proof) => $proof->toArray(),
                $this->outsideProvisioningProofs,
            ),
            'safe_summary' => $this->safeSummary,
            'calculated_at' => $this->calculatedAt->format(DATE_ATOM),
        ];
    }
}
