<?php

namespace App\Support\Provisioning;

use App\DTO\Provisioning\InstallationReadinessAssessmentResult;
use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProof;

/**
 * Sérialisation admin des résultats readiness (TASK 3W).
 */
final class ProvisioningInstallationReadinessPresentation
{
    /**
     * @return array<string, mixed>
     */
    public static function present(InstallationReadinessAssessmentResult $assessment): array
    {
        $blockers = array_map(
            static fn (InstallationReadinessProof $proof) => self::proof($proof),
            [
                ...$assessment->failedRequiredProofs,
                ...$assessment->manualInterventionRequiredProofs,
            ],
        );

        if ($assessment->missingRequiredProofCodes !== []) {
            foreach ($assessment->missingRequiredProofCodes as $code) {
                $definition = InstallationReadinessProofCatalog::definition($code);
                $blockers[] = [
                    'code' => $code,
                    'status' => 'missing',
                    'message' => 'Preuve obligatoire absente du rapport.',
                    'domain' => $definition['domain'] ?? 'unknown',
                ];
            }
        }

        $warnings = array_map(
            static fn (InstallationReadinessProof $proof) => self::proof($proof),
            $assessment->recommendedWarnings,
        );

        return [
            'state' => $assessment->outcome,
            'label' => self::outcomeLabel($assessment->outcome),
            'summary' => $assessment->safeSummary,
            'can_execute' => $assessment->isReady(),
            'proofs' => self::primaryProofs($assessment),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'calculated_at' => $assessment->calculatedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function primaryProofs(InstallationReadinessAssessmentResult $assessment): array
    {
        $proofs = [
            ...$assessment->failedRequiredProofs,
            ...$assessment->manualInterventionRequiredProofs,
            ...$assessment->recommendedWarnings,
        ];

        if ($proofs === []) {
            $proofs = array_merge(
                $proofs,
                $assessment->optionalProofs,
            );
        }

        return array_map(
            static fn (InstallationReadinessProof $proof) => self::proof($proof),
            array_slice($proofs, 0, 12),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function proof(InstallationReadinessProof $proof): array
    {
        return [
            'code' => $proof->code,
            'domain' => $proof->domain,
            'level' => $proof->level,
            'status' => $proof->status,
            'message' => $proof->safeSummary,
            'source' => $proof->source,
            'verified_at' => $proof->verifiedAt?->format('Y-m-d H:i:s'),
        ];
    }

    public static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            InstallationReadinessDecisionOutcome::READY => 'Prête',
            InstallationReadinessDecisionOutcome::NOT_READY => 'Non prête',
            InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED => 'Intervention requise',
            InstallationReadinessDecisionOutcome::FAILED => 'Échec de préparation',
            default => $outcome,
        };
    }
}
