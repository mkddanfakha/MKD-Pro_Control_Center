<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class StorageProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly StorageSetupAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_STORAGE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_STORAGE;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->configureStorage($context);
    }
}
