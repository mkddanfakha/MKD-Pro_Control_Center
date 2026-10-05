<?php

namespace Tests\Fakes\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;

/**
 * Enregistre l'ordre d'appel sans effet externe.
 */
final class FakeSpyStep implements ProvisioningStep
{
    /** @var list<string> */
    public static array $executionOrder = [];

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
        self::$executionOrder[] = $this->key;

        return ProvisioningStepResult::succeeded($this->key);
    }

    public static function reset(): void
    {
        self::$executionOrder = [];
    }
}
