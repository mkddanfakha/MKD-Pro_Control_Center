<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalApplicationDeployAdapter extends AbstractLocalInfrastructureAdapter implements ApplicationDeployAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'application_deploy', 'deployment');
    }

    public function deployApplication(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'deployed_version' => $context->targetVersion ?? 'local-simulated',
            ],
        );
    }
}
