<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProvisioningRunTransition extends RuntimeException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $runId = null,
        ?string $message = null,
    ) {
        $suffix = $runId !== null ? " (run #{$runId})" : '';

        parent::__construct($message ?? sprintf(
            'Transition de provisioning interdite : %s → %s%s.',
            $fromStatus,
            $toStatus,
            $suffix,
        ));
    }
}
