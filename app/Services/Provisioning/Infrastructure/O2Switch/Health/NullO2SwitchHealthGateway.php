<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHealthGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchHealthGateway implements O2SwitchHealthGateway
{
    public function checkHealth(
        ProvisioningContext $context,
        O2SwitchHealthConfiguration $configuration,
        O2SwitchHealthPlan $plan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_health_protocol_pending',
            sprintf(
                'Aucun gateway Health o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'health_check',
                'operation_planned' => $plan->safeOperationLabel(),
                'checks_planned_count' => count($plan->checks),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'health_check',
                'installation_id' => $context->installationId,
                'health_plan_fingerprint' => $plan->healthPlanFingerprint,
                'health_scope_checks' => $plan->healthScopeCheckKeys(),
                'readiness_scope_checks' => $plan->readinessScopeCheckKeys(),
                ...$plan->deploymentTarget->safePublicMetadata(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
