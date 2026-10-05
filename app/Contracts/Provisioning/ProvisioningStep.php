<?php

namespace App\Contracts\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;

/**
 * Contrat d'une étape du pipeline provisioning (implémentations futures — TASK 339+).
 */
interface ProvisioningStep
{
    /**
     * Identifiant stable de l'étape (unique dans un registre exécutable).
     */
    public function stepKey(): string;

    /**
     * Ordre d'exécution relatif (tri croissant, puis stepKey en cas d'égalité).
     */
    public function order(): int;

    public function execute(ProvisioningContext $context): ProvisioningStepResult;
}
