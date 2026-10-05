<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;

final class LocalInstallationReadinessPersistenceAdapter implements InstallationReadinessPersistenceAdapter
{
    public ?InfrastructureAdapterResult $forcedNextResult = null;

    public function __construct(
        private readonly LocalControlledInfrastructureState $state,
    ) {}

    public function persistMarker(ProvisioningContext $context, string $marker): InfrastructureAdapterResult
    {
        $operationKey = 'readiness:'.$marker;
        $this->state->recordCall($operationKey, $context);

        if ($this->forcedNextResult !== null) {
            return $this->forcedNextResult;
        }

        $installationId = $context->installationId;

        if ($this->state->hasReadinessMarker($installationId, $marker)) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'readiness_marker' => $marker,
                    'idempotent_replay' => true,
                ],
                metadata: $this->localMetadata($context, $marker),
            );
        }

        $missing = $this->missingPrerequisites($installationId, $marker);
        if ($missing !== []) {
            return InfrastructureAdapterResult::failed(
                'readiness_prerequisites_missing',
                'Preuves readiness simulées manquantes pour « '.$marker.' ».',
                retryable: false,
                category: ProvisioningErrorCategory::Definitive,
                outputSummary: ['missing_operations' => $missing],
                metadata: $this->localMetadata($context, $marker),
            );
        }

        $this->state->recordReadinessMarker($installationId, $marker);

        return InfrastructureAdapterResult::succeeded(
            outputSummary: ['readiness_marker' => $marker],
            metadata: $this->localMetadata($context, $marker),
        );
    }

    /**
     * @return list<string>
     */
    private function missingPrerequisites(int $installationId, string $marker): array
    {
        $required = match ($marker) {
            'deployed' => [
                'application_deploy',
                'environment_configuration',
                'dependency_installation',
                'application_build',
                'database_migrate',
                'storage_link',
                'cache_warmup',
            ],
            'verified' => ['health_check'],
            'ready' => ['admin_user_bootstrap', 'installation_modules'],
            default => [],
        };

        $missing = [];
        foreach ($required as $operation) {
            if (! $this->state->isOperationApplied($installationId, $operation)) {
                $missing[] = $operation;
            }
        }

        if ($marker === 'verified' && ! $this->state->hasReadinessMarker($installationId, 'deployed')) {
            $missing[] = 'readiness:deployed';
        }

        if ($marker === 'ready') {
            if (! $this->state->hasReadinessMarker($installationId, 'verified')) {
                $missing[] = 'readiness:verified';
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function localMetadata(ProvisioningContext $context, string $marker): array
    {
        return [
            'execution_mode' => 'local_test',
            'simulated' => true,
            'operation' => 'readiness:'.$marker,
            'installation_id' => $context->installationId,
        ];
    }
}
