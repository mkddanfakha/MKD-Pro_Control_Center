<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHealthGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthPlan;

final class FakeO2SwitchHealthGateway implements O2SwitchHealthGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<string> */
    public array $fingerprintsSeen = [];

    public function checkHealth(
        ProvisioningContext $context,
        O2SwitchHealthConfiguration $configuration,
        O2SwitchHealthPlan $plan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'operation' => $plan->safeOperationLabel(),
            'health_plan_fingerprint' => $plan->healthPlanFingerprint,
            'checks_count' => count($plan->checks),
        ];

        $fingerprint = $plan->healthPlanFingerprint;

        if (in_array($fingerprint, $this->fingerprintsSeen, true)) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'operation_completed' => 'gestion_application_health',
                    'idempotent_replay' => true,
                    'overall_health' => 'healthy',
                    'readiness_state' => 'unchanged',
                ],
                metadata: [
                    'health_state' => 'idempotent_replay',
                    'health_plan_fingerprint' => $fingerprint,
                ],
            );
        }

        $this->fingerprintsSeen[] = $fingerprint;

        if ($this->nextResult !== null) {
            return $this->nextResult;
        }

        return InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_health',
            'Fake gateway par défaut.',
        );
    }
}
