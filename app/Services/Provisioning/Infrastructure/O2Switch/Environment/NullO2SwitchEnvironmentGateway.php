<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchEnvironmentGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchEnvironmentGateway implements O2SwitchEnvironmentGateway
{
    public function configureEnvironment(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
        O2SwitchGestionEnvBuildResult $buildResult,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_environment_protocol_pending',
            sprintf(
                'Aucun gateway Environment o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'environment_configuration',
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'environment_configuration',
                'installation_id' => $context->installationId,
                'env_file_path' => $buildResult->envFileAbsolutePath,
                'configuration_fingerprint' => $buildResult->fingerprint(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
