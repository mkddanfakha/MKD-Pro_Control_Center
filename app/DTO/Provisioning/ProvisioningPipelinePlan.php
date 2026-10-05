<?php

namespace App\DTO\Provisioning;

/**
 * Plan d'exécution ordonné — sans effet externe (TASK 339).
 */
final class ProvisioningPipelinePlan
{
    /**
     * @param  list<string>  $orderedStepKeys
     */
    public function __construct(
        public readonly int $provisioningRunId,
        public readonly array $orderedStepKeys,
    ) {}

    public function stepCount(): int
    {
        return count($this->orderedStepKeys);
    }
}
