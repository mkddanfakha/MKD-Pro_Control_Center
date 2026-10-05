<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalAdminBootstrapAdapter extends AbstractLocalInfrastructureAdapter implements AdminBootstrapAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'admin_user_bootstrap', 'admin');
    }

    public function bootstrapAdmin(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'admin_bootstrap' => 'local_simulated',
            ],
        );
    }
}
