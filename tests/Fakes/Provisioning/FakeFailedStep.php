<?php

namespace Tests\Fakes\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\DTO\Provisioning\ProvisioningStepResult;

final class FakeFailedStep implements ProvisioningStep
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
        return ProvisioningStepResult::failed(
            $this->key,
            'fake_failure',
            'Échec synthétique de test.',
            retryable: false,
            category: ProvisioningErrorCategory::Definitive,
        );
    }
}
