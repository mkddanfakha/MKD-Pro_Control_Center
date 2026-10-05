<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Cache;

use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchCacheGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTargetResolver;

final class O2SwitchCacheAdapter implements CacheWarmupAdapter
{
    public function __construct(
        private readonly O2SwitchCacheGateway $gateway,
        private readonly ?O2SwitchCacheConfiguration $configuration = null,
    ) {}

    public function warmCache(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchCacheConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_cache_disabled',
                'Cache o2switch désactivé (PROVISIONING_O2SWITCH_CACHE_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_cache_not_configured',
                'Cache o2switch activé mais configuration incomplète (compte, racine de déploiement).',
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
                $targetResolved['code'] ?? 'o2switch_cache_deployment_path_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $targetResolved['message'] ?? 'Chemin applicatif invalide pour le cache.',
                    $context->installationId,
                ),
                outputSummary: [
                    'implementation_state' => 'deployment_path_rejected',
                    'contract' => 'cache_warmup',
                ],
                metadata: [
                    'implementation_state' => 'deployment_path_rejected',
                    'operation' => 'cache_warmup',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $deploymentTarget = $targetResolved['target'];

        $resolved = O2SwitchCacheCommandPlanResolver::resolve($deploymentTarget);
        if ($resolved['plan'] === null) {
            return $this->manualIntervention(
                $resolved['code'] ?? 'o2switch_cache_not_configured',
                $resolved['message'] ?? 'Plan de cache invalide.',
                $configuration,
                $context,
            );
        }

        $commandPlan = $resolved['plan'];

        if (! $configuration->dryRun && ! self::cacheWarmupReady($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_cache_prerequisites_not_ready',
                sprintf(
                    'Prérequis storage/environment non confirmés (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'cache_warmup',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'cache_warmup',
                    'installation_id' => $context->installationId,
                    ...$deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $databaseCacheIssue = self::databaseCacheStorePrerequisiteIssue($context, $commandPlan);
        if ($databaseCacheIssue !== null && ! $configuration->dryRun) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $databaseCacheIssue,
                sprintf(
                    'Prérequis cache database non confirmés (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'cache_warmup',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'cache_warmup',
                    'installation_id' => $context->installationId,
                    'cache_store' => $commandPlan->cacheStore,
                    ...$deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'cache_warmup',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                    'planned_cache_commands' => $commandPlan->plannedCommandNames(),
                    'excluded_cache_commands' => $commandPlan->excludedArtisanCommands,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'cache_warmup',
                    'installation_id' => $context->installationId,
                    'cache_fingerprint' => $commandPlan->cacheFingerprint,
                    'cache_store' => $commandPlan->cacheStore,
                    'session_driver' => $commandPlan->sessionDriver,
                    'queue_connection' => $commandPlan->queueConnection,
                    'cache_database_migration_reference' => config('provisioning.gestion.cache_database_migration_reference'),
                    ...$deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_cache_not_configured',
                'Cache o2switch activé mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->warmCache($context, $configuration, $commandPlan);
    }

    private static function cacheWarmupReady(ProvisioningContext $context): bool
    {
        if (($context->externalReferences['gestion_cache_warmup_ready'] ?? false) === true) {
            return true;
        }

        return ($context->externalReferences['gestion_storage_step_ready'] ?? false) === true
            && ($context->externalReferences['gestion_environment_prepared'] ?? false) === true;
    }

    private static function databaseCacheStorePrerequisiteIssue(
        ProvisioningContext $context,
        O2SwitchCacheCommandPlan $commandPlan,
    ): ?string {
        if ($commandPlan->cacheStore !== 'database') {
            return null;
        }

        if (($context->externalReferences['gestion_cache_database_tables_ready'] ?? false) === true) {
            return null;
        }

        if (($context->externalReferences['gestion_migrate_step_ready'] ?? false) === true) {
            return null;
        }

        return 'o2switch_cache_database_tables_unconfirmed';
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchCacheConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'cache_warmup',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'cache_warmup',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
