<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\Models\ProvisioningRunStep;

final class MarkDeployedProvisioningStep extends ReadinessContractStep
{
    public function __construct(InstallationReadinessPersistenceAdapter $readinessAdapter)
    {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_MARK_DEPLOYED), $readinessAdapter);
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_MARK_DEPLOYED;
    }

    protected function readinessMarker(): string
    {
        return 'deployed';
    }
}
