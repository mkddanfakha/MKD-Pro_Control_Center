<?php

namespace App\DTO\Provisioning;

/**
 * Résultat global d'une exécution orchestrée — sans persistance ProvisioningRun / Step.
 */
final class ProvisioningPipelineResult
{
    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    /**
     * @param  list<ProvisioningStepResult>  $stepResults
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $stepResults,
        public readonly ?string $stoppedAtStepKey = null,
        public readonly ?string $operatorMessage = null,
    ) {}

    /**
     * @param  list<ProvisioningStepResult>  $stepResults
     */
    public static function succeeded(array $stepResults): self
    {
        return new self(
            outcome: self::OUTCOME_SUCCEEDED,
            stepResults: $stepResults,
        );
    }

    /**
     * @param  list<ProvisioningStepResult>  $priorResults
     */
    public static function failed(
        ProvisioningStepResult $terminalResult,
        array $priorResults,
    ): self {
        return new self(
            outcome: self::OUTCOME_FAILED,
            stepResults: [...$priorResults, $terminalResult],
            stoppedAtStepKey: $terminalResult->stepKey,
            operatorMessage: $terminalResult->operatorMessage,
        );
    }

    /**
     * @param  list<ProvisioningStepResult>  $priorResults
     */
    public static function manualInterventionRequired(
        ProvisioningStepResult $terminalResult,
        array $priorResults,
    ): self {
        return new self(
            outcome: self::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            stepResults: [...$priorResults, $terminalResult],
            stoppedAtStepKey: $terminalResult->stepKey,
            operatorMessage: $terminalResult->operatorMessage,
        );
    }

    public function isTerminalFailure(): bool
    {
        return in_array($this->outcome, [
            self::OUTCOME_FAILED,
            self::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
        ], true);
    }
}
