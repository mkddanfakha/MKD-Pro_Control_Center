<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalEnvironmentConfigurationAdapter extends AbstractLocalInfrastructureAdapter implements EnvironmentConfigurationAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'environment_configuration', 'environment');
    }

    public function configureEnvironment(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'env_keys_applied' => ['APP_ENV', 'APP_URL'],
            ],
        );
    }
}
