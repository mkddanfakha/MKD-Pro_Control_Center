<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchAdminGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTargetResolver;

final class O2SwitchAdminAdapter implements AdminBootstrapAdapter
{
    public function __construct(
        private readonly O2SwitchAdminGateway $gateway,
        private readonly ?O2SwitchAdminConfiguration $configuration = null,
    ) {}

    public function bootstrapAdmin(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchAdminConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_admin_disabled',
                'Admin o2switch désactivé (PROVISIONING_O2SWITCH_ADMIN_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_admin_not_configured',
                'Admin o2switch activé mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $identityResolved = O2SwitchAdminBootstrapIdentityResolver::resolve($context);
        if ($identityResolved['identity'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $identityResolved['code'] ?? 'o2switch_admin_identity_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $identityResolved['message'] ?? 'Identité admin invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'implementation_state' => 'admin_identity_rejected',
                    'contract' => 'admin_user_bootstrap',
                ],
                metadata: [
                    'implementation_state' => 'admin_identity_rejected',
                    'operation' => 'admin_user_bootstrap',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $bootstrapIdentity = $identityResolved['identity'];

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
                $targetResolved['code'] ?? 'o2switch_admin_deployment_path_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $targetResolved['message'] ?? 'Chemin applicatif invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'admin_user_bootstrap',
                ],
                metadata: [
                    'operation' => 'admin_user_bootstrap',
                    'installation_id' => $context->installationId,
                    ...$bootstrapIdentity->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $planResolved = O2SwitchAdminPlanResolver::resolve($targetResolved['target'], $bootstrapIdentity);
        if ($planResolved['plan'] === null) {
            return $this->manualIntervention(
                $planResolved['code'] ?? 'o2switch_admin_not_configured',
                $planResolved['message'] ?? 'Plan admin invalide.',
                $configuration,
                $context,
            );
        }

        $plan = $planResolved['plan'];

        if (! $configuration->dryRun && ! self::adminBootstrapReady($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_admin_prerequisites_not_ready',
                sprintf(
                    'Prérequis cache/migrate non confirmés (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'admin_user_bootstrap',
                    'operation_planned' => $plan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'admin_user_bootstrap',
                    'installation_id' => $context->installationId,
                    ...$bootstrapIdentity->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->dryRun && ! self::secureDeliveryChannelDeclared($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_admin_secure_delivery_required',
                sprintf(
                    'Identifiants administrateur requis via canal sécurisé hors metadata (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'admin_user_bootstrap',
                    'operation_planned' => $plan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'admin_user_bootstrap',
                    'installation_id' => $context->installationId,
                    'admin_secure_delivery' => 'channel_required',
                    ...$bootstrapIdentity->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'admin_user_bootstrap',
                    'operation_planned' => $plan->safeOperationLabel(),
                    'logical_operations_planned' => $plan->logicalOperations,
                    'artisan_steps_planned_count' => count($plan->artisanCommandSteps),
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'admin_user_bootstrap',
                    'installation_id' => $context->installationId,
                    'admin_plan_fingerprint' => $plan->adminPlanFingerprint,
                    'admin_role_value' => $plan->adminRoleValue,
                    'admin_unique_identity_field' => $plan->adminUniqueIdentityField,
                    'gestion_admin_mechanism' => config('provisioning.gestion.admin_bootstrap_mechanism'),
                    ...$bootstrapIdentity->safePublicMetadata(),
                    ...$plan->deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_admin_not_configured',
                'Admin o2switch activé mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->bootstrapAdmin($context, $configuration, $plan);
    }

    private static function adminBootstrapReady(ProvisioningContext $context): bool
    {
        if (($context->externalReferences['gestion_admin_bootstrap_ready'] ?? false) === true) {
            return true;
        }

        return ($context->externalReferences['gestion_cache_step_ready'] ?? false) === true
            && ($context->externalReferences['gestion_migrate_step_ready'] ?? false) === true;
    }

    private static function secureDeliveryChannelDeclared(ProvisioningContext $context): bool
    {
        return ($context->externalReferences['gestion_admin_secure_delivery_ready'] ?? false) === true;
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchAdminConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'admin_user_bootstrap',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'admin_user_bootstrap',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
