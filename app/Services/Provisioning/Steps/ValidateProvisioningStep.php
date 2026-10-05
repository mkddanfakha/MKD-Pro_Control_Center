<?php

namespace App\Services\Provisioning\Steps;

use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\Readiness\InstallationReadinessEvaluationService;
use App\Support\Provisioning\ProvisioningInstallationReadinessPresentation;

final class ValidateProvisioningStep extends AbstractProductionProvisioningStep
{
    public function __construct(
        private readonly InstallationReadinessEvaluationService $readinessEvaluation,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_VALIDATE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_VALIDATE;
    }

    public function execute(ProvisioningContext $context): ProvisioningStepResult
    {
        $assessment = $this->readinessEvaluation->evaluateProvisioningContext(
            $context,
            preflightReport: null,
            runPreflightWhenMissing: false,
        );

        if ($assessment->isReady()) {
            return ProvisioningStepResult::succeeded(
                $this->stepKey(),
                outputSummary: [
                    'readiness_outcome' => $assessment->outcome,
                    'readiness_summary' => $assessment->safeSummary,
                ],
                metadata: [
                    'scope' => 'control_center_readiness',
                ],
            );
        }

        $reason = $this->readinessEvaluation->executionBlockReason($assessment)
            ?? ProvisioningInstallationReadinessPresentation::outcomeLabel($assessment->outcome);

        return match ($assessment->outcome) {
            InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED => ProvisioningStepResult::manualInterventionRequired(
                $this->stepKey(),
                'readiness_manual_intervention_required',
                $reason,
                metadata: [
                    'readiness_outcome' => $assessment->outcome,
                    'readiness_summary' => $assessment->safeSummary,
                ],
            ),
            InstallationReadinessDecisionOutcome::FAILED => ProvisioningStepResult::failed(
                $this->stepKey(),
                'readiness_failed',
                $reason,
                retryable: false,
                category: ProvisioningErrorCategory::Definitive,
                metadata: [
                    'readiness_outcome' => $assessment->outcome,
                    'readiness_summary' => $assessment->safeSummary,
                ],
            ),
            default => ProvisioningStepResult::failed(
                $this->stepKey(),
                'readiness_not_ready',
                $reason,
                retryable: true,
                category: ProvisioningErrorCategory::Retryable,
                metadata: [
                    'readiness_outcome' => $assessment->outcome,
                    'readiness_summary' => $assessment->safeSummary,
                ],
            ),
        };
    }
}
