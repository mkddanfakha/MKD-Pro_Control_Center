<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\Models\ProvisioningRunStep;

final class MarkVerifiedProvisioningStep extends ReadinessContractStep
{
    public function __construct(InstallationReadinessPersistenceAdapter $readinessAdapter)
    {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_MARK_VERIFIED), $readinessAdapter);
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_MARK_VERIFIED;
    }

    protected function readinessMarker(): string
    {
        return 'verified';
    }
}
