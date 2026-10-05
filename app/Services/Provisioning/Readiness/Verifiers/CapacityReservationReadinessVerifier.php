<?php

namespace App\Services\Provisioning\Readiness\Verifiers;

use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Services\Provisioning\Readiness\InstallationReadinessProofBuilder;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

/**
 * Vérifie la disponibilité de l'adapter de réservation — sans appeler reserve (TASK 3W).
 */
final class CapacityReservationReadinessVerifier implements InstallationReadinessProofVerifier
{
    private const SOURCE = 'capacity_reservation_readiness_verifier';

    public function __construct(
        private readonly CapacityReservationAdapter $capacityReservationAdapter,
    ) {}

    public function verify(InstallationReadinessVerificationContext $context): array
    {
        $available = ! $this->capacityReservationAdapter instanceof UnavailableCapacityReservationAdapter;

        return [
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_CAPACITY_RESERVATION_AVAILABLE,
                $available
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED,
                self::SOURCE,
                $available
                    ? 'capacity_reservation_adapter_available'
                    : 'capacity_reservation_adapter_unavailable',
                $context,
            ),
        ];
    }
}
