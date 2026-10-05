<?php

namespace App\DTO\Provisioning;

/**
 * Résultat déclaratif d'une étape — messages opérateur, pas de stack trace brute.
 */
final class ProvisioningStepResult
{
    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * @param  array<string, mixed>  $outputSummary
     * @param  array<string, mixed>  $metadata  Données redacted / allowlist uniquement
     */
    public function __construct(
        public readonly string $stepKey,
        public readonly string $outcome,
        public readonly ?string $code = null,
        public readonly ?string $operatorMessage = null,
        public readonly array $outputSummary = [],
        public readonly bool $retryable = false,
        public readonly array $metadata = [],
        public readonly ?ProvisioningErrorCategory $errorCategory = null,
    ) {
        if ($operatorMessage !== null) {
            $this->guardMessage($operatorMessage);
        }

        $this->assertNoForbiddenSecrets($outputSummary, 'outputSummary');
        $this->assertNoForbiddenSecrets($metadata, 'metadata');
    }

    public static function succeeded(
        string $stepKey,
        array $outputSummary = [],
        array $metadata = [],
    ): self {
        return new self(
            stepKey: $stepKey,
            outcome: self::OUTCOME_SUCCEEDED,
            outputSummary: $outputSummary,
            metadata: $metadata,
        );
    }

    public static function failed(
        string $stepKey,
        string $code,
        string $operatorMessage,
        bool $retryable,
        ProvisioningErrorCategory $category,
        array $outputSummary = [],
        array $metadata = [],
    ): self {
        return new self(
            stepKey: $stepKey,
            outcome: self::OUTCOME_FAILED,
            code: $code,
            operatorMessage: $operatorMessage,
            outputSummary: $outputSummary,
            retryable: $retryable,
            metadata: $metadata,
            errorCategory: $category,
        );
    }

    public static function manualInterventionRequired(
        string $stepKey,
        string $code,
        string $operatorMessage,
        array $outputSummary = [],
        array $metadata = [],
    ): self {
        return new self(
            stepKey: $stepKey,
            outcome: self::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            code: $code,
            operatorMessage: $operatorMessage,
            outputSummary: $outputSummary,
            retryable: false,
            metadata: $metadata,
            errorCategory: ProvisioningErrorCategory::ManualInterventionRequired,
        );
    }

    public static function skipped(
        string $stepKey,
        ?string $operatorMessage = null,
        array $metadata = [],
    ): self {
        return new self(
            stepKey: $stepKey,
            outcome: self::OUTCOME_SKIPPED,
            operatorMessage: $operatorMessage,
            metadata: $metadata,
        );
    }

    public function isTerminalFailure(): bool
    {
        return in_array($this->outcome, [
            self::OUTCOME_FAILED,
            self::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
        ], true);
    }

    private function guardMessage(string $message): void
    {
        if (str_contains($message, 'password=') || str_contains($message, 'Bearer ')) {
            throw new \InvalidArgumentException('Message opérateur : contenu sensible interdit.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoForbiddenSecrets(array $data, string $field): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && ProvisioningContext::isForbiddenSecretKey($key)) {
                throw new \InvalidArgumentException(
                    "Clé interdite dans ProvisioningStepResult->{$field} : {$key}."
                );
            }

            if (is_array($value)) {
                $this->assertNoForbiddenSecrets($value, $field);
            }
        }
    }
}
