<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Modules;

use App\Contracts\Provisioning\Infrastructure\InstallationModulesAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchModulesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTargetResolver;

final class O2SwitchModulesAdapter implements InstallationModulesAdapter
{
    public function __construct(
        private readonly O2SwitchModulesGateway $gateway,
        private readonly ?O2SwitchModulesConfiguration $configuration = null,
    ) {}

    public function configureModules(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchModulesConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_modules_disabled',
                'Modules o2switch désactivés (PROVISIONING_O2SWITCH_MODULES_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_modules_not_configured',
                'Modules o2switch activés mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $selectionResolved = O2SwitchModulesModuleSelectionResolver::resolve($context);
        if ($selectionResolved['module_ids'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $selectionResolved['code'] ?? 'o2switch_modules_selection_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $selectionResolved['message'] ?? 'Sélection de modules invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'installation_modules',
                ],
                metadata: [
                    'operation' => 'installation_modules',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $deployConfiguration = new O2SwitchStorageConfiguration(
            provider: $configuration->provider,
            enabled: true,
            dryRun: true,
            accountLogicalId: $configuration->accountLogicalId,
            deploymentRootBase: $configuration->deploymentRootBase,
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: $configuration->forbiddenAbsolutePathPrefixes,
        );

        $targetResolved = O2SwitchStorageDeploymentTargetResolver::resolve(
            $context,
            $deployConfiguration,
            $configuration->forbiddenAbsolutePathPrefixes,
        );

        if ($targetResolved['target'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $targetResolved['code'] ?? 'o2switch_modules_deployment_path_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $targetResolved['message'] ?? 'Chemin applicatif invalide pour les modules.',
                    $context->installationId,
                ),
                outputSummary: [
                    'implementation_state' => 'deployment_path_rejected',
                    'contract' => 'installation_modules',
                ],
                metadata: [
                    'implementation_state' => 'deployment_path_rejected',
                    'operation' => 'installation_modules',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $planResolved = O2SwitchModulesPlanResolver::resolve(
            $targetResolved['target'],
            $context,
            $selectionResolved['module_ids'],
            $configuration->dryRun || ! $configuration->isLiveReady(),
        );

        if ($planResolved['plan'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $planResolved['code'] ?? 'o2switch_modules_not_configured',
                sprintf(
                    '%s (installation #%d).',
                    $planResolved['message'] ?? 'Plan modules invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'installation_modules',
                ],
                metadata: [
                    'operation' => 'installation_modules',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $plan = $planResolved['plan'];

        if ($configuration->dryRun) {
            $outputSummary = [
                'simulated_domain' => 'installation_modules',
                'operation_planned' => $plan->safeOperationLabel(),
                'requested_module_ids' => $plan->requestedModuleIds,
                'noop_module_ids' => $plan->noopModuleIds,
                'logical_operations_planned' => $plan->logicalOperations,
                'planned_artisan_commands' => $plan->plannedArtisanCommandNames(),
                'dry_run' => true,
            ];

            if ($plan->isEmpty()) {
                $outputSummary['catalog_decision_required'] = true;
            }

            return InfrastructureAdapterResult::succeeded(
                outputSummary: $outputSummary,
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'installation_modules',
                    'installation_id' => $context->installationId,
                    'modules_plan_fingerprint' => $plan->modulesPlanFingerprint,
                    'gestion_modules_activation_mechanism' => config('provisioning.gestion.modules_activation_mechanism'),
                    ...$plan->deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! self::modulesStepReady($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_modules_prerequisites_not_ready',
                sprintf(
                    'Prérequis migrate/admin non confirmés (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'installation_modules',
                    'operation_planned' => $plan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'installation_modules',
                    'installation_id' => $context->installationId,
                    ...$plan->deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_modules_not_configured',
                'Modules o2switch activés mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        if ($plan->isEmpty()) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_modules_selection_pending',
                sprintf(
                    'Aucun module demandé : décision catalogue / gestion_modules_requested requise (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'installation_modules',
                    'catalog_decision_required' => true,
                ],
                metadata: [
                    'operation' => 'installation_modules',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        return $this->gateway->configureModules($context, $configuration, $plan);
    }

    private static function modulesStepReady(ProvisioningContext $context): bool
    {
        if (($context->externalReferences['gestion_modules_step_ready'] ?? false) === true) {
            return true;
        }

        return ($context->externalReferences['gestion_migrate_step_ready'] ?? false) === true
            && ($context->externalReferences['gestion_admin_bootstrap_ready'] ?? false) === true;
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchModulesConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'installation_modules',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'installation_modules',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
