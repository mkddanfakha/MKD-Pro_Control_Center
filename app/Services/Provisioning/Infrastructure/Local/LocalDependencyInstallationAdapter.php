<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalDependencyInstallationAdapter extends AbstractLocalInfrastructureAdapter implements DependencyInstallationAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'dependency_installation', 'dependencies');
    }

    public function installDependencies(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'composer_install' => 'local_simulated_ok',
            ],
        );
    }
}
