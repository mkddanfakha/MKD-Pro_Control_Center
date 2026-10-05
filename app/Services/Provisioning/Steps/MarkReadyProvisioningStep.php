<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\Models\ProvisioningRunStep;

final class MarkReadyProvisioningStep extends ReadinessContractStep
{
    public function __construct(InstallationReadinessPersistenceAdapter $readinessAdapter)
    {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_MARK_READY), $readinessAdapter);
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_MARK_READY;
    }

    protected function readinessMarker(): string
    {
        return 'ready';
    }
}
