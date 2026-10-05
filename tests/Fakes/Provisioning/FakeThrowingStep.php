<?php

namespace Tests\Fakes\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;
use RuntimeException;

final class FakeThrowingStep implements ProvisioningStep
{
    public function __construct(
        private readonly string $key,
        private readonly int $stepOrder,
        private readonly RuntimeException $exception,
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
        throw $this->exception;
    }
}
