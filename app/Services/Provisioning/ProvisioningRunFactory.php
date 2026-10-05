<?php

namespace App\Services\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use App\Exceptions\Provisioning\ProvisioningRunCreationException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use Illuminate\Support\Facades\DB;

/**
 * Crée une demande de provisioning (run pending + steps pending) sans exécuter le pipeline.
 */
final class ProvisioningRunFactory
{
    public function __construct(
        private readonly ProvisioningStepRegistry $registry,
    ) {}

    /**
     * @return array{
     *     can_create_request: bool,
     *     unavailable_reason: string|null,
     *     button_label: string,
     *     retry_basis_run_id: int|null
     * }
     */
    public function describeCreateRequestAvailability(Installation $installation): array
    {
        $installation->loadMissing('client');

        try {
            $this->assertInstallationEligible($installation);
            $retryOfRunId = $this->resolveRetryOfRunId($installation);

            return [
                'can_create_request' => true,
                'unavailable_reason' => null,
                'button_label' => $retryOfRunId !== null
                    ? 'Créer une nouvelle demande de provisioning'
                    : 'Créer une demande de provisioning',
                'retry_basis_run_id' => $retryOfRunId,
            ];
        } catch (ProvisioningRunCreationException $exception) {
            return [
                'can_create_request' => false,
                'unavailable_reason' => $exception->getMessage(),
                'button_label' => 'Créer une demande de provisioning',
                'retry_basis_run_id' => null,
            ];
        }
    }

    public function createRequest(
        Installation $installation,
        ?int $requestedByUserId = null,
    ): ProvisioningRun {
        $installation->loadMissing('client');

        $this->assertInstallationEligible($installation);
        $retryOfRunId = $this->resolveRetryOfRunId($installation);

        $connection = $installation->getConnectionName();
        $trigger = $retryOfRunId !== null ? 'retry' : 'manual';
        $metadata = $this->buildSafeMetadata($installation);

        return DB::connection($connection)->transaction(function () use (
            $installation,
            $requestedByUserId,
            $trigger,
            $retryOfRunId,
            $metadata,
            $connection,
        ): ProvisioningRun {
            /** @var ProvisioningRun $run */
            $run = ProvisioningRun::on($connection)->create([
                'installation_id' => $installation->id,
                'status' => ProvisioningRun::STATUS_PENDING,
                'trigger' => $trigger,
                'requested_by' => $requestedByUserId,
                'retry_of_run_id' => $retryOfRunId,
                'metadata' => $metadata,
            ]);

            $this->syncPendingStepsFromRegistry($run);

            return $run->fresh(['steps']);
        });
    }

    private function assertInstallationEligible(Installation $installation): void
    {
        if ($installation->id === null) {
            throw new ProvisioningRunCreationException('Installation introuvable ou non persistée.');
        }

        if ($installation->status === 'terminated' || $installation->terminated_at !== null) {
            throw new ProvisioningRunCreationException(
                'Impossible de créer une demande de provisioning pour une installation terminée.',
            );
        }

        $connection = $installation->getConnectionName();

        if (! Client::on($connection)->whereKey($installation->client_id)->exists()) {
            throw new ProvisioningRunCreationException(
                'Impossible de créer une demande de provisioning : le client associé est introuvable.',
            );
        }

        if (trim((string) $installation->name) === '') {
            throw new ProvisioningRunCreationException(
                'Impossible de créer une demande de provisioning : le nom de l\'installation est requis.',
            );
        }

        if (trim((string) $installation->subdomain) === '') {
            throw new ProvisioningRunCreationException(
                'Impossible de créer une demande de provisioning : le sous-domaine est requis.',
            );
        }
    }

    private function resolveRetryOfRunId(Installation $installation): ?int
    {
        $hasActive = $installation->provisioningRuns()
            ->whereIn('status', [
                ProvisioningRun::STATUS_PENDING,
                ProvisioningRun::STATUS_RUNNING,
            ])
            ->exists();

        if ($hasActive) {
            throw new ProvisioningRunCreationException(
                'Une demande ou un provisioning est déjà en cours pour cette installation.',
            );
        }

        /** @var ProvisioningRun|null $latest */
        $latest = $installation->provisioningRuns()
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        if ($latest->status === ProvisioningRun::STATUS_SUCCEEDED) {
            throw new ProvisioningRunCreationException(
                'Un provisioning a déjà réussi pour cette installation ; aucune nouvelle demande n\'est autorisée.',
            );
        }

        if (in_array($latest->status, [
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
            ProvisioningRun::STATUS_CANCELLED,
        ], true)) {
            return (int) $latest->id;
        }

        throw new ProvisioningRunCreationException(
            'État de provisioning existant incompatible avec une nouvelle demande.',
        );
    }

    private function syncPendingStepsFromRegistry(ProvisioningRun $run): void
    {
        $descriptors = $this->registry->orderedDescriptors();

        foreach ($descriptors as $descriptor) {
            $run->steps()->create([
                'step_key' => $descriptor['step_key'],
                'step_order' => $descriptor['step_order'],
                'status' => ProvisioningRunStep::STATUS_PENDING,
                'attempt' => 1,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSafeMetadata(Installation $installation): array
    {
        $metadata = [
            'installation_id' => $installation->id,
            'client_id' => $installation->client_id,
            'installation_name' => $installation->name,
            'subdomain' => $installation->subdomain,
            'domain' => $installation->domain,
            'database_name' => $installation->database_name,
            'database_host' => $installation->database_host,
        ];

        foreach (array_keys($metadata) as $key) {
            if (ProvisioningContext::isForbiddenSecretKey($key)) {
                throw new ProvisioningRunCreationException(
                    'Métadonnée de demande interdite : clé sensible détectée.',
                );
            }
        }

        return $metadata;
    }
}
