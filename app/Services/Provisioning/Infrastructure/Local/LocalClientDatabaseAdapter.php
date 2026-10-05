<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class LocalClientDatabaseAdapter extends AbstractLocalInfrastructureAdapter implements ClientDatabaseAdapter
{
    public function __construct(LocalControlledInfrastructureState $state)
    {
        parent::__construct($state, 'client_database', 'database');
    }

    public function provisionDatabase(ProvisioningContext $context): InfrastructureAdapterResult
    {
        return $this->executeLocalOperation(
            $context,
            fn (int $installationId): array => [
                'database_name' => $context->databaseName ?? 'local_db_'.$installationId,
                'database_host' => $context->databaseHost ?? 'local-mysql-simulated',
            ],
        );
    }
}
