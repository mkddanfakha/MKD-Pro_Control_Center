<?php

namespace App\DTO\Provisioning;

/**
 * Élément de plan de validation live — sans secret (TASK 383).
 */
final class InfrastructureLiveValidationPlanItem
{
    public function __construct(
        public readonly string $serviceKey,
        public readonly string $label,
        public readonly string $readOnlyOperation,
        public readonly bool $credentialsConfigured,
        public readonly bool $hostOrZoneConfigured,
        public readonly bool $probeConfigured,
        public readonly bool $probeSafe,
        public readonly ?string $probeRejectionCode,
        public readonly bool $wouldExecuteReadOnlyCall,
    ) {}
}
