<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDeployGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;

final class FakeO2SwitchDeployGateway implements O2SwitchDeployGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function deployApplication(
        ProvisioningContext $context,
        O2SwitchDeployConfiguration $configuration,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'account_logical_id' => $configuration->accountLogicalId,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_deploy',
            'Fake gateway par défaut.',
        );
    }
}
