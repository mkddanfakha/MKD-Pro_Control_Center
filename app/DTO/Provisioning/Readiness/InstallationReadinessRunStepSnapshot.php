<?php

namespace App\DTO\Provisioning\Readiness;

/**
 * Snapshot lecture seule d'un step de run — sans summaries sensibles (TASK 381).
 */
final class InstallationReadinessRunStepSnapshot
{
    public function __construct(
        public readonly string $stepKey,
        public readonly int $stepOrder,
        public readonly string $status,
    ) {}
}
