<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Base des adaptateurs locaux contrôlés (simulation, sans réseau).
 */
abstract class AbstractLocalInfrastructureAdapter
{
    public ?InfrastructureAdapterResult $forcedNextResult = null;

    public function __construct(
        protected readonly LocalControlledInfrastructureState $state,
        protected readonly string $operationKey,
        protected readonly string $simulatedDomain,
    ) {}

    /**
     * @param  callable(int): array<string, mixed>  $applyFirstTime
     */
    protected function executeLocalOperation(
        ProvisioningContext $context,
        callable $applyFirstTime,
    ): InfrastructureAdapterResult {
        $this->state->recordCall($this->operationKey, $context);

        if ($this->forcedNextResult !== null) {
            return $this->forcedNextResult;
        }

        $installationId = $context->installationId;

        if ($this->state->isOperationApplied($installationId, $this->operationKey)) {
            return $this->localSucceeded($context, [
                'idempotent_replay' => true,
                ...$this->state->operationPayload($installationId, $this->operationKey),
            ]);
        }

        $payload = $applyFirstTime($installationId);
        $this->state->markOperationApplied($installationId, $this->operationKey, $payload);

        return $this->localSucceeded($context, $payload);
    }

    /**
     * @param  array<string, mixed>  $outputSummary
     */
    protected function localSucceeded(ProvisioningContext $context, array $outputSummary = []): InfrastructureAdapterResult
    {
        return InfrastructureAdapterResult::succeeded(
            outputSummary: array_merge(
                ['simulated_domain' => $this->simulatedDomain],
                $outputSummary,
            ),
            metadata: [
                'execution_mode' => 'local_test',
                'simulated' => true,
                'operation' => $this->operationKey,
                'installation_id' => $context->installationId,
            ],
        );
    }
}
