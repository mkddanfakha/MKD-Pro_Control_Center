<?php

namespace App\Services\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\DTO\Provisioning\ProvisioningStepResult;

final class InfrastructureAdapterResultMapper
{
    public static function toStepResult(string $stepKey, InfrastructureAdapterResult $result): ProvisioningStepResult
    {
        return match ($result->outcome) {
            InfrastructureAdapterResult::OUTCOME_SUCCEEDED => ProvisioningStepResult::succeeded(
                $stepKey,
                $result->outputSummary,
                $result->metadata,
            ),
            InfrastructureAdapterResult::OUTCOME_FAILED => ProvisioningStepResult::failed(
                $stepKey,
                $result->code ?? 'adapter_failed',
                $result->operatorMessage ?? 'Échec de l\'adaptateur d\'infrastructure.',
                $result->retryable,
                $result->errorCategory ?? ProvisioningErrorCategory::Definitive,
                $result->outputSummary,
                $result->metadata,
            ),
            InfrastructureAdapterResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED => ProvisioningStepResult::manualInterventionRequired(
                $stepKey,
                $result->code ?? 'manual_intervention_required',
                $result->operatorMessage ?? 'Intervention manuelle requise.',
                $result->outputSummary,
                $result->metadata,
            ),
            default => throw new \InvalidArgumentException('Outcome adaptateur inconnu : '.$result->outcome),
        };
    }
}
