<?php

namespace App\DTO\Provisioning;

/**
 * Résultat validation live infrastructure — TASK 383.
 */
final class InfrastructureLiveValidationOutcome
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly bool $liveNetworkAttempted,
        public readonly InfrastructureLiveValidationPlan $plan,
        public readonly ?InfrastructurePreflightReport $report,
        public readonly string $globalState,
        public readonly ?string $blockedReasonCode,
        public readonly ?string $blockedReasonMessage,
    ) {}
}
