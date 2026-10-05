<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalHostingSpaceAdapter extends AbstractLocalInfrastructureAdapter implements HostingSpaceAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'hosting_panel', 'hosting');
    }

    public function prepareHosting(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'document_root' => '/local/simulated/'.$installationId,
            ],
        );
    }
}
