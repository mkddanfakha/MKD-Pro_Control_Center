<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchStorageGateway;
use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchStorageAdapter implements StorageSetupAdapter
{
    public function __construct(
        private readonly O2SwitchStorageGateway $gateway,
        private readonly ?O2SwitchStorageConfiguration $configuration = null,
    ) {}

    public function configureStorage(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchStorageConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_storage_disabled',
                'Storage o2switch désactivé (PROVISIONING_O2SWITCH_STORAGE_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isDryRunReady()) {
            return $this->manualIntervention(
                'o2switch_storage_not_configured',
                'Storage o2switch activé mais configuration incomplète (compte, racine de déploiement).',
                $configuration,
                $context,
            );
        }

        $targetResolved = O2SwitchStorageDeploymentTargetResolver::resolve(
            $context,
            $configuration,
            $configuration->forbiddenAbsolutePathPrefixes,
        );

        if ($targetResolved['target'] === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                $targetResolved['code'] ?? 'o2switch_storage_deployment_path_invalid',
                sprintf(
                    '%s (installation #%d).',
                    $targetResolved['message'] ?? 'Chemin storage invalide.',
                    $context->installationId,
                ),
                outputSummary: [
                    'implementation_state' => 'deployment_path_rejected',
                    'contract' => 'storage_setup',
                ],
                metadata: [
                    'implementation_state' => 'deployment_path_rejected',
                    'operation' => 'storage_setup',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $deploymentTarget = $targetResolved['target'];

        $resolved = O2SwitchStorageCommandPlanResolver::resolve($configuration, $deploymentTarget);
        if ($resolved['plan'] === null) {
            return $this->manualIntervention(
                $resolved['code'] ?? 'o2switch_storage_not_configured',
                $resolved['message'] ?? 'Plan storage invalide.',
                $configuration,
                $context,
            );
        }

        $commandPlan = $resolved['plan'];

        if (! $configuration->dryRun && ! self::deployFilesystemReady($context)) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_storage_deploy_not_ready',
                sprintf(
                    'Prérequis deploy/migrate non confirmés pour le filesystem (installation #%d).',
                    $context->installationId,
                ),
                outputSummary: [
                    'contract' => 'storage_setup',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                ],
                metadata: [
                    'operation' => 'storage_setup',
                    'installation_id' => $context->installationId,
                    ...$deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'storage_setup',
                    'operation_planned' => $commandPlan->safeOperationLabel(),
                    'writable_directories_planned' => $commandPlan->requiredWritableRelativeDirectories,
                    'storage_link_planned' => $commandPlan->requiresStorageLink,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'storage_setup',
                    'installation_id' => $context->installationId,
                    'storage_fingerprint' => $commandPlan->storageFingerprint,
                    'default_filesystem_disk' => $commandPlan->defaultFilesystemDisk,
                    'application_filesystem_disks' => $commandPlan->applicationFilesystemDisks,
                    'storage_link_mapping' => $commandPlan->storageLinkMapping,
                    ...$deploymentTarget->safePublicMetadata(),
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_storage_not_configured',
                'Storage o2switch activé mais accès distant incomplet.',
                $configuration,
                $context,
            );
        }

        return $this->gateway->configureStorage($context, $configuration, $commandPlan);
    }

    private static function deployFilesystemReady(ProvisioningContext $context): bool
    {
        if (($context->externalReferences['gestion_deploy_filesystem_ready'] ?? false) === true) {
            return true;
        }

        return ($context->externalReferences['gestion_environment_prepared'] ?? false) === true;
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchStorageConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'storage_setup',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'storage_setup',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
