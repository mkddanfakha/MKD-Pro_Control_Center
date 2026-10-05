<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalDnsRecordAdapter extends AbstractLocalInfrastructureAdapter implements DnsRecordAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'dns', 'dns');
    }

    public function configureDns(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'record_name' => $context->subdomain,
                'simulated_propagation' => 'local_ok',
            ],
        );
    }
}
