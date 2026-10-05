<?php

namespace App\DTO\Provisioning;

use App\Support\Provisioning\ProvisioningSecretSanitizer;

/**
 * Résultat d'un adaptateur d'infrastructure — sans secrets (TASK 354).
 */
final class InfrastructureAdapterResult
{
    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    /**
     * @param  array<string, mixed>  $outputSummary
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $code = null,
        public readonly ?string $operatorMessage = null,
        public readonly array $outputSummary = [],
        public readonly bool $retryable = false,
        public readonly array $metadata = [],
        public readonly ?ProvisioningErrorCategory $errorCategory = null,
    ) {
        $this->assertNoForbiddenSecrets($outputSummary);
        $this->assertNoForbiddenSecrets($metadata);
        if ($operatorMessage !== null) {
            $this->guardMessage($operatorMessage);
        }
    }

    /**
     * @param  array<string, mixed>  $outputSummary
     * @param  array<string, mixed>  $metadata
     */
    public static function succeeded(
        array $outputSummary = [],
        array $metadata = [],
    ): self {
        return new self(
            outcome: self::OUTCOME_SUCCEEDED,
            outputSummary: $outputSummary,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $outputSummary
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(
        string $code,
        string $operatorMessage,
        bool $retryable,
        ProvisioningErrorCategory $category,
        array $outputSummary = [],
        array $metadata = [],
    ): self {
        return new self(
            outcome: self::OUTCOME_FAILED,
            code: $code,
            operatorMessage: $operatorMessage,
            outputSummary: $outputSummary,
            retryable: $retryable,
            metadata: $metadata,
            errorCategory: $category,
        );
    }

    /**
     * @param  array<string, mixed>  $outputSummary
     * @param  array<string, mixed>  $metadata
     */
    public static function manualInterventionRequired(
        string $code,
        string $operatorMessage,
        array $outputSummary = [],
        array $metadata = [],
    ): self {
        return new self(
            outcome: self::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            code: $code,
            operatorMessage: $operatorMessage,
            outputSummary: $outputSummary,
            metadata: $metadata,
            errorCategory: ProvisioningErrorCategory::ManualInterventionRequired,
        );
    }

    private function guardMessage(string $message): void
    {
        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage($message);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoForbiddenSecrets(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && ProvisioningContext::isForbiddenSecretKey($key)) {
                throw new \InvalidArgumentException("Clé interdite dans InfrastructureAdapterResult : {$key}.");
            }

            if (is_array($value)) {
                $this->assertNoForbiddenSecrets($value);
            }
        }
    }
}
