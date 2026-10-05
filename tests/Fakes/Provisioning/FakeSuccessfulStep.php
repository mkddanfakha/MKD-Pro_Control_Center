<?php

namespace Tests\Fakes\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;

final class FakeSuccessfulStep implements ProvisioningStep
{
    /** @var list<int> */
    public static array $receivedRunIds = [];

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
        self::$receivedRunIds[] = $context->provisioningRunId;

        return ProvisioningStepResult::succeeded($this->key, ['noop' => true]);
    }

    public static function reset(): void
    {
        self::$receivedRunIds = [];
    }
}
