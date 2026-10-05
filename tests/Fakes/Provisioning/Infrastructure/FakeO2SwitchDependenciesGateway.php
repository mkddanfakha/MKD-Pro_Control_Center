<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDependenciesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesConfiguration;

final class FakeO2SwitchDependenciesGateway implements O2SwitchDependenciesGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function installDependencies(
        ProvisioningContext $context,
        O2SwitchDependenciesConfiguration $configuration,
        O2SwitchDependenciesCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'working_directory' => $commandPlan->workingDirectory,
            'operations' => $commandPlan->safeOperationLabels(),
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_dependencies',
            'Fake gateway par défaut.',
        );
    }
}
