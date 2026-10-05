<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalApplicationBuildAdapter extends AbstractLocalInfrastructureAdapter implements ApplicationBuildAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'application_build', 'build');
    }

    public function buildApplication(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'build_artifact' => 'local-artifact-'.$installationId,
            ],
        );
    }
}
