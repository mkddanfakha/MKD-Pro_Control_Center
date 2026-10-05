<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\Models\ProvisioningRunStep;
use InvalidArgumentException;

/**
 * Registre canonique (18 clés) + registre exécutable d'instances {@see ProvisioningStep}.
 */
class ProvisioningStepRegistry
{
    /**
     * @var array<string, ProvisioningStep>
     */
    private array $executableSteps = [];

    public function register(ProvisioningStep $step): void
    {
        $key = $step->stepKey();

        if (isset($this->executableSteps[$key])) {
            throw new InvalidArgumentException("Étape provisioning déjà enregistrée : {$key}.");
        }

        $this->executableSteps[$key] = $step;
    }

    public function isEmpty(): bool
    {
        return $this->executableSteps === [];
    }

    public function hasExecutableStep(string $stepKey): bool
    {
        return isset($this->executableSteps[$stepKey]);
    }

    public function getExecutableStep(string $stepKey): ProvisioningStep
    {
        if (! isset($this->executableSteps[$stepKey])) {
            throw new InvalidArgumentException("Étape provisioning inconnue : {$stepKey}.");
        }

        return $this->executableSteps[$stepKey];
    }

    /**
     * @return list<ProvisioningStep>
     */
    public function orderedExecutableSteps(): array
    {
        $steps = array_values($this->executableSteps);

        usort(
            $steps,
            static fn (ProvisioningStep $a, ProvisioningStep $b): int => $a->order() <=> $b->order()
                ?: strcmp($a->stepKey(), $b->stepKey()),
        );

        return $steps;
    }

    /**
     * @return list<string>
     */
    public function orderedStepKeys(): array
    {
        return ProvisioningRunStep::CANONICAL_STEP_KEYS;
    }

    public function stepCount(): int
    {
        return count($this->orderedStepKeys());
    }

    public function contains(string $stepKey): bool
    {
        return in_array($stepKey, $this->orderedStepKeys(), true);
    }

    public function orderIndexOf(string $stepKey): int
    {
        $index = array_search($stepKey, $this->orderedStepKeys(), true);

        if ($index === false) {
            throw new InvalidArgumentException("Étape provisioning inconnue : {$stepKey}.");
        }

        return (int) $index;
    }

    /**
     * @param  list<string>  $keys
     */
    public function assertRegistryIntegrity(array $keys): void
    {
        if (count($keys) !== 18) {
            throw new InvalidArgumentException('Le registre doit contenir exactement 18 étapes.');
        }

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Duplication de step_key dans le registre.');
        }

        foreach ($this->orderedStepKeys() as $expected) {
            if (! in_array($expected, $keys, true)) {
                throw new InvalidArgumentException("Étape manquante : {$expected}.");
            }
        }

        foreach ($keys as $key) {
            if (! $this->contains($key)) {
                throw new InvalidArgumentException("Étape inconnue : {$key}.");
            }
        }
    }

    /**
     * @return list<array{step_key: string, step_order: int}>
     */
    public function orderedDescriptors(): array
    {
        $descriptors = [];

        foreach ($this->orderedStepKeys() as $index => $stepKey) {
            $descriptors[] = [
                'step_key' => $stepKey,
                'step_order' => $index + 1,
            ];
        }

        return $descriptors;
    }
}
