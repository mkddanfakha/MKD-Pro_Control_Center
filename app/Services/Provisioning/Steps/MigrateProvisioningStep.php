<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class MigrateProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly DatabaseMigrationAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_MIGRATE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_MIGRATE;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->runMigrations($context);
    }
}
