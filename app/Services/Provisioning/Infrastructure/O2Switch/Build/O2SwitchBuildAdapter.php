<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Build;

use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchBuildGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchBuildAdapter implements ApplicationBuildAdapter
{
    public function __construct(
        private readonly O2SwitchBuildGateway $gateway,
        private readonly ?O2SwitchBuildConfiguration $configuration = null,
    ) {}

    public function buildApplication(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchBuildConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_build_disabled',
                'Build o2switch désactivé (PROVISIONING_O2SWITCH_BUILD_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_build_not_configured',
                'Build o2switch activé mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $resolved = O2SwitchBuildCommandPlanResolver::resolve($context, $configuration);
        if ($resolved['plan'] === null) {
            return $this->manualIntervention(
                $resolved['code'] ?? 'o2switch_build_not_configured',
                $resolved['message'] ?? 'Plan de build invalide.',
                $configuration,
                $context,
            );
        }

        $commandPlan = $resolved['plan'];

        $missingViteKeys = O2SwitchBuildCommandPlanResolver::missingRequiredPublicViteKeys($context);
        if ($missingViteKeys !== [] && ! $configuration->dryRun) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_build_vite_env_missing',
                sprintf(
                    'Variables VITE publiques absentes pour le build : %s.',
                    implode(', ', $missingViteKeys),
                ),
                outputSummary: [
                    'contract' => 'application_build',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'application_build',
                    'installation_id' => $context->installationId,
                    'missing_vite_keys' => $missingViteKeys,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'application_build',
                    'working_directory' => $commandPlan->workingDirectory,
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                    'expected_artifacts' => $commandPlan->expectedArtifactRelativePaths,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'application_build',
                    'installation_id' => $context->installationId,
                    'build_fingerprint' => $commandPlan->buildFingerprint,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_build_not_configured',
                'Build o2switch activé mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->buildApplication($context, $configuration, $commandPlan);
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchBuildConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'application_build',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'application_build',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
