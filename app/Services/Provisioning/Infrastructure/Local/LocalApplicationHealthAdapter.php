<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalApplicationHealthAdapter extends AbstractLocalInfrastructureAdapter implements ApplicationHealthAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'health_check', 'health');
    }

    public function checkHealth(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'health_status' => 'local_ok',
            ],
        );
    }
}
