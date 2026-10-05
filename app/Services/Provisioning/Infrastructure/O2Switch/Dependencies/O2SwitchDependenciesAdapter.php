<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Dependencies;

use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDependenciesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchDependenciesAdapter implements DependencyInstallationAdapter
{
    public function __construct(
        private readonly O2SwitchDependenciesGateway $gateway,
        private readonly ?O2SwitchDependenciesConfiguration $configuration = null,
    ) {}

    public function installDependencies(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchDependenciesConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_dependencies_disabled',
                'Dependencies o2switch désactivées (PROVISIONING_O2SWITCH_DEPENDENCIES_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_dependencies_not_configured',
                'Dependencies o2switch activées mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $resolved = O2SwitchDependenciesCommandPlanResolver::resolve($context, $configuration);
        if ($resolved['plan'] === null) {
            return $this->manualIntervention(
                $resolved['code'] ?? 'o2switch_dependencies_not_configured',
                $resolved['message'] ?? 'Plan de dépendances invalide.',
                $configuration,
                $context,
            );
        }

        $commandPlan = $resolved['plan'];

        $incompatibleCode = O2SwitchDependenciesCommandPlanResolver::validateRuntimeCompatibility(
            $configuration,
            is_string($context->externalReferences['reported_php_version'] ?? null)
                ? $context->externalReferences['reported_php_version']
                : null,
        );

        if ($incompatibleCode !== null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $incompatibleCode,
                sprintf(
                    'Version PHP distante incompatible avec Gestion (minimum %s).',
                    $configuration->gestionPhpVersionMinimum,
                ),
                outputSummary: [
                    'contract' => 'dependency_installation',
                    'operations_planned' => $commandPlan->safeOperationLabels(),
                ],
                metadata: [
                    'operation' => 'dependency_installation',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'dependency_installation',
                    'working_directory' => $commandPlan->workingDirectory,
                    'operations_planned' => $commandPlan->safeOperationLabels(),
                    'lock_strategy' => 'composer.lock',
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'dependency_installation',
                    'installation_id' => $context->installationId,
                    'lock_fingerprint' => $commandPlan->lockFingerprint,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_dependencies_not_configured',
                'Dependencies o2switch activées mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->installDependencies($context, $configuration, $commandPlan);
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchDependenciesConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'dependency_installation',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'dependency_installation',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
