<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchEnvironmentGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchEnvironmentAdapter implements EnvironmentConfigurationAdapter
{
    public function __construct(
        private readonly O2SwitchEnvironmentGateway $gateway,
        private readonly ?O2SwitchEnvironmentConfiguration $configuration = null,
    ) {}

    public function configureEnvironment(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchEnvironmentConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_environment_disabled',
                'Environment o2switch désactivé (PROVISIONING_O2SWITCH_ENVIRONMENT_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_environment_not_configured',
                'Environment o2switch activé mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $assembly = O2SwitchGestionEnvAssembly::assemble($context, $configuration);
        if ($assembly['result'] === null) {
            return $this->manualIntervention(
                $assembly['code'] ?? 'o2switch_environment_not_configured',
                $assembly['message'] ?? 'Configuration Environment invalide.',
                $configuration,
                $context,
            );
        }

        $buildResult = $assembly['result'];

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'environment_configuration',
                    'env_file_path' => $buildResult->envFileAbsolutePath,
                    'env_keys_applied' => $buildResult->builder->appliedKeyNames(),
                    'app_url' => $buildResult->appUrl,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'environment_configuration',
                    'installation_id' => $context->installationId,
                    'configuration_fingerprint' => $buildResult->fingerprint(),
                    'app_key_state' => 'pending_generation',
                    'db_auth_state' => 'pending_vault',
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_environment_not_configured',
                'Environment o2switch activé mais accès cPanel incomplet pour écriture distante.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->configureEnvironment($context, $configuration, $buildResult);
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchEnvironmentConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'environment_configuration',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'environment_configuration',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
