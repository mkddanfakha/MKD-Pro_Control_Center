<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHealthGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTargetResolver;

final class O2SwitchHealthAdapter implements ApplicationHealthAdapter
{
    public function __construct(
        private readonly O2SwitchHealthGateway $gateway,
        private readonly ?O2SwitchHealthConfiguration $configuration = null,
    ) {}

    public function checkHealth(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchHealthConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_health_disabled',
                'Health o2switch désactivé (PROVISIONING_O2SWITCH_HEALTH_ENABLED=false) : aucun contrôle externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_health_not_configured',
                'Health o2switch activé mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
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
                $targetResolved['code'] ?? 'o2switch_health_deployment_path_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $targetResolved['message'] ?? 'Chemin applicatif invalide pour health.',
                    $context->installationId,
                ),
                outputSummary: [
                    'implementation_state' => 'deployment_path_rejected',
                    'contract' => 'health_check',
                ],
                metadata: [
                    'implementation_state' => 'deployment_path_rejected',
                    'operation' => 'health_check',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $urlResolved = O2SwitchHealthApplicationUrlResolver::resolve($context);
        $applicationUrl = $urlResolved['url'];

        $planResolved = O2SwitchHealthPlanResolver::resolve(
            $targetResolved['target'],
            $applicationUrl,
        );

        if ($planResolved['plan'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $planResolved['code'] ?? 'o2switch_health_not_configured',
                sprintf(
                    '%s (installation #%d).',
                    $planResolved['message'] ?? 'Plan health invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'health_check',
                ],
                metadata: [
                    'operation' => 'health_check',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $plan = $planResolved['plan'];

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'application_health',
                    'operation_planned' => $plan->safeOperationLabel(),
                    'checks_planned' => $plan->checksAsSafePlanArray(),
                    'health_scope_checks' => $plan->healthScopeCheckKeys(),
                    'readiness_scope_checks' => $plan->readinessScopeCheckKeys(),
                    'application_public_base_url_resolved' => $applicationUrl !== null,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'health_check',
                    'installation_id' => $context->installationId,
                    'health_plan_fingerprint' => $plan->healthPlanFingerprint,
                    'health_laravel_up_path' => config('provisioning.gestion.health_laravel_up_path'),
                    'target_url_code' => $urlResolved['code'],
                    ...$plan->deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($plan->requiresHttpTarget() && $applicationUrl === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $urlResolved['code'] ?? 'o2switch_health_target_url_pending',
                sprintf(
                    '%s (installation #%d).',
                    $urlResolved['message'] ?? 'URL cible health manquante.',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'health_check',
                    'operation_planned' => $plan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'health_check',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! self::healthPrerequisitesReady($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_health_prerequisites_not_ready',
                sprintf(
                    'Prérequis provisioning non confirmés (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'health_check',
                    'operation_planned' => $plan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'health_check',
                    'installation_id' => $context->installationId,
                    ...$plan->deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_health_not_configured',
                'Health o2switch activé mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->checkHealth($context, $configuration, $plan);
    }

    private static function healthPrerequisitesReady(ProvisioningContext $context): bool
    {
        if (($context->externalReferences['gestion_health_step_ready'] ?? false) === true) {
            return true;
        }

        return ($context->externalReferences['gestion_migrate_step_ready'] ?? false) === true
            && ($context->externalReferences['gestion_storage_step_ready'] ?? false) === true
            && ($context->externalReferences['gestion_cache_step_ready'] ?? false) === true;
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchHealthConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'health_check',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'health_check',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
