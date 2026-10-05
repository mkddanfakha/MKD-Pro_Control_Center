<?php

namespace App\Services\Provisioning\Infrastructure\Unavailable;

use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\UnavailableInfrastructureAdapterSupport;

final class UnavailableDnsRecordAdapter implements DnsRecordAdapter
{
    public function configureDns(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return UnavailableInfrastructureAdapterSupport::manualInterventionForContract('dns', $context);
    }
}
