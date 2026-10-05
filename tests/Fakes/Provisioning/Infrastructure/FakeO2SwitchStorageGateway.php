<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchStorageGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;

final class FakeO2SwitchStorageGateway implements O2SwitchStorageGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function configureStorage(
        ProvisioningContext $context,
        O2SwitchStorageConfiguration $configuration,
        O2SwitchStorageCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'operation' => $commandPlan->safeOperationLabel(),
            'storage_fingerprint' => $commandPlan->storageFingerprint,
            'storage_target_installation_id' => $commandPlan->deploymentTarget->installationId,
            'deploy_relative_segment' => $commandPlan->deploymentTarget->deployRelativeSegment,
            'requires_storage_link' => $commandPlan->requiresStorageLink,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_storage',
            'Fake gateway par défaut.',
        );
    }
}
