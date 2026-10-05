<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalDatabaseMigrationAdapter extends AbstractLocalInfrastructureAdapter implements DatabaseMigrationAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'database_migrate', 'migration');
    }

    public function runMigrations(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'migrations_applied' => 'local_simulated',
            ],
        );
    }
}
