<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Modules;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchModulesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchModulesGateway implements O2SwitchModulesGateway
{
    public function configureModules(
        ProvisioningContext $context,
        O2SwitchModulesConfiguration $configuration,
        O2SwitchModulesPlan $plan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_modules_protocol_pending',
            sprintf(
                'Aucun gateway Modules o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'installation_modules',
                'operation_planned' => $plan->safeOperationLabel(),
                'requested_module_ids' => $plan->requestedModuleIds,
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'installation_modules',
                'installation_id' => $context->installationId,
                'modules_plan_fingerprint' => $plan->modulesPlanFingerprint,
                'planned_artisan_commands' => $plan->plannedArtisanCommandNames(),
                ...$plan->deploymentTarget->safePublicMetadata(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
