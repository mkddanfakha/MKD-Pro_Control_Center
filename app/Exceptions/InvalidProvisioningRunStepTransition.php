<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProvisioningRunStepTransition extends RuntimeException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $stepId = null,
        ?string $message = null,
    ) {
        $suffix = $stepId !== null ? " (step #{$stepId})" : '';

        parent::__construct($message ?? sprintf(
            'Transition d\'étape de provisioning interdite : %s → %s%s.',
            $fromStatus,
            $toStatus,
            $suffix,
        ));
    }
}
