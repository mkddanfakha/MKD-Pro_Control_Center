<?php

namespace App\Exceptions\Provisioning;

use App\DTO\Provisioning\ProvisioningStepResult;
use RuntimeException;
use Throwable;

/**
 * Exception d'étape après persistance d'un état cohérent (run/step non laissés en running).
 */
final class ProvisioningStepExecutionException extends RuntimeException
{
    public function __construct(
        public readonly string $stepKey,
        public readonly ProvisioningStepResult $stepResult,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Échec inattendu lors de l\'étape « %s ».', $stepKey),
            0,
            $previous,
        );
    }
}
