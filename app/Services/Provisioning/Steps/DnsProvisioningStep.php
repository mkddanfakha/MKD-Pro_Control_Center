<?php

namespace App\Services\Provisioning\Steps;

use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRunStep;

final class DnsProvisioningStep extends DelegatingInfrastructureAdapterStep
{
    public function __construct(
        private readonly DnsRecordAdapter $adapter,
    ) {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_DNS));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_DNS;
    }

    protected function invokeAdapter(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->adapter->configureDns($context);
    }
}
