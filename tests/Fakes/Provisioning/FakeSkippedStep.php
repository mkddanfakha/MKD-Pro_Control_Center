<?php

namespace Tests\Fakes\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;

final class FakeSkippedStep implements ProvisioningStep
{
    public function __construct(
        private readonly string $key,
        private readonly int $stepOrder,
    ) {}

    public function stepKey(): string
    {
        return $this->key;
    }

    public function order(): int
    {
        return $this->stepOrder;
    }

    public function execute(ProvisioningContext $context): ProvisioningStepResult
    {
        return ProvisioningStepResult::skipped($this->key, 'Étape synthétique ignorée.');
    }
}
