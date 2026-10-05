<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface DnsRecordAdapter
{
    public function configureDns(ProvisioningContext $context): InfrastructureAdapterResult;
}
