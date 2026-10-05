<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\ProvisioningStep;
use App\Models\ProvisioningRunStep;

abstract class AbstractProductionProvisioningStep implements ProvisioningStep
{
    public function __construct(
        protected readonly int $canonicalOrder,
    ) {}

    public function order(): int
    {
        return $this->canonicalOrder;
    }

    protected static function canonicalOrderFor(string $stepKey): int
    {
        $index = array_search($stepKey, ProvisioningRunStep::CANONICAL_STEP_KEYS, true);

        if ($index === false) {
            throw new \InvalidArgumentException("Clé d'étape canonique inconnue : {$stepKey}.");
        }

        return (int) $index + 1;
    }
}
