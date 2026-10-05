<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchModulesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesPlan;

final class FakeO2SwitchModulesGateway implements O2SwitchModulesGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<string> */
    public array $fingerprintsSeen = [];

    public function configureModules(
        ProvisioningContext $context,
        O2SwitchModulesConfiguration $configuration,
        O2SwitchModulesPlan $plan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'operation' => $plan->safeOperationLabel(),
            'modules_plan_fingerprint' => $plan->modulesPlanFingerprint,
            'requested_module_ids' => $plan->requestedModuleIds,
        ];

        $fingerprint = $plan->modulesPlanFingerprint;

        if (in_array($fingerprint, $this->fingerprintsSeen, true)) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'operation_completed' => 'gestion_installation_modules',
                    'idempotent_replay' => true,
                ],
                metadata: [
                    'modules_state' => 'idempotent_replay',
                    'modules_plan_fingerprint' => $fingerprint,
                ],
            );
        }

        $this->fingerprintsSeen[] = $fingerprint;

        if ($this->nextResult !== null) {
            return $this->nextResult;
        }

        return InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_modules',
            'Fake gateway par défaut.',
        );
    }
}
