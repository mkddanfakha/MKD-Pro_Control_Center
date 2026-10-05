<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchEnvironmentGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchGestionEnvBuildResult;

final class FakeO2SwitchEnvironmentGateway implements O2SwitchEnvironmentGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function configureEnvironment(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
        O2SwitchGestionEnvBuildResult $buildResult,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'env_file_path' => $buildResult->envFileAbsolutePath,
            'fingerprint' => $buildResult->fingerprint(),
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_environment',
            'Fake gateway par défaut.',
        );
    }
}
