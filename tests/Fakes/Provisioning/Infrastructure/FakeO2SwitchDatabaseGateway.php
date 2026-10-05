<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDatabaseGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;

final class FakeO2SwitchDatabaseGateway implements O2SwitchDatabaseGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function provisionDatabase(
        ProvisioningContext $context,
        O2SwitchDatabaseConfiguration $configuration,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'account_logical_id' => $configuration->accountLogicalId,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_database',
            'Fake gateway par défaut.',
        );
    }
}
