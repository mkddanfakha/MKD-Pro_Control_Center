<?php

namespace App\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InfrastructurePreflightReport;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;

/**
 * Compose preuves locales, préflight infrastructure et placeholders externes (TASK 381 / 382).
 */
final class InstallationReadinessCompositeProofCollector
{
    public function __construct(
        private readonly InstallationReadinessLocalProofCollector $localProofCollector = new InstallationReadinessLocalProofCollector,
        private readonly InfrastructurePreflightReadinessProofBridge $preflightBridge = new InfrastructurePreflightReadinessProofBridge,
    ) {}

    /**
     * @return list<InstallationReadinessProof>
     */
    public function collect(
        InstallationReadinessVerificationContext $context,
        ?InfrastructurePreflightReport $preflightReport = null,
    ): array {
        /** @var array<string, InstallationReadinessProof> $byCode */
        $byCode = [];

        foreach ($this->localProofCollector->collect($context) as $proof) {
            $byCode[$proof->code] = $proof;
        }

        if ($preflightReport !== null) {
            foreach ($this->preflightBridge->transform($preflightReport, $context->verifiedAt) as $proof) {
                $byCode[$proof->code] = $proof;
            }
        }

        return array_values($byCode);
    }
}
