<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchAdminGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchAdminGateway implements O2SwitchAdminGateway
{
    public function bootstrapAdmin(
        ProvisioningContext $context,
        O2SwitchAdminConfiguration $configuration,
        O2SwitchAdminPlan $plan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_admin_protocol_pending',
            sprintf(
                'Aucun gateway Admin o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'admin_user_bootstrap',
                'operation_planned' => $plan->safeOperationLabel(),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'admin_user_bootstrap',
                'installation_id' => $context->installationId,
                'admin_plan_fingerprint' => $plan->adminPlanFingerprint,
                ...$plan->bootstrapIdentity->safePublicMetadata(),
                ...$plan->deploymentTarget->safePublicMetadata(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
