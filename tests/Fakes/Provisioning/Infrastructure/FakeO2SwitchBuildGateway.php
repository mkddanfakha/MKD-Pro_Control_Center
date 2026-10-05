<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchBuildGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildConfiguration;

final class FakeO2SwitchBuildGateway implements O2SwitchBuildGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function buildApplication(
        ProvisioningContext $context,
        O2SwitchBuildConfiguration $configuration,
        O2SwitchBuildCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'working_directory' => $commandPlan->workingDirectory,
            'operation' => $commandPlan->safeOperationLabel(),
            'fingerprint' => $commandPlan->buildFingerprint,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_build',
            'Fake gateway par défaut.',
        );
    }
}
