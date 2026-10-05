<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalStorageSetupAdapter extends AbstractLocalInfrastructureAdapter implements StorageSetupAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'storage_link', 'storage');
    }

    public function configureStorage(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'storage_linked' => true,
            ],
        );
    }
}
