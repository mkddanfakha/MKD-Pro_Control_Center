<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class DatabaseProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly ClientDatabaseAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_DATABASE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_DATABASE;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->provisionDatabase($context);
    }
}
