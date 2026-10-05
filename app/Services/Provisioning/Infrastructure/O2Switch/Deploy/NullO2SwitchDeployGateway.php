<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDeployGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchDeployGateway implements O2SwitchDeployGateway
{
    public function deployApplication(
        ProvisioningContext $context,
        O2SwitchDeployConfiguration $configuration,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_deploy_protocol_pending',
            sprintf(
                'Aucun gateway de déploiement o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'application_deploy',
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'application_deploy',
                'provider' => $configuration->provider,
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
