<?php

namespace App\Services\Provisioning\Infrastructure\Local;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * État simulé en mémoire — TASK 355 (local_test uniquement, jamais production réelle).
 */
final class LocalControlledInfrastructureState
{
    /** @var list<array{operation: string, installation_id: int, sequence: int}> */
    public array $callLog = [];

    /** @var array<int, array<string, mixed>> */
    private array $operationsByInstallation = [];

    /** @var array<int, list<string>> */
    private array $readinessMarkersByInstallation = [];

    private int $sequence = 0;

    public function recordCall(string $operation, ProvisioningContext $context): void
    {
        $this->callLog[] = [
            'operation' => $operation,
            'installation_id' => $context->installationId,
            'sequence' => ++$this->sequence,
        ];
    }

    public function isOperationApplied(int $installationId, string $operationKey): bool
    {
        return array_key_exists($operationKey, $this->operationsByInstallation[$installationId] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markOperationApplied(int $installationId, string $operationKey, array $payload = []): void
    {
        $this->operationsByInstallation[$installationId][$operationKey] = $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function operationPayload(int $installationId, string $operationKey): array
    {
        $payload = $this->operationsByInstallation[$installationId][$operationKey] ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  list<string>  $operationKeys
     */
    public function hasAllOperations(int $installationId, array $operationKeys): bool
    {
        foreach ($operationKeys as $key) {
            if (! $this->isOperationApplied($installationId, $key)) {
                return false;
            }
        }

        return true;
    }

    public function recordReadinessMarker(int $installationId, string $marker): void
    {
        $this->readinessMarkersByInstallation[$installationId] ??= [];

        if (! in_array($marker, $this->readinessMarkersByInstallation[$installationId], true)) {
            $this->readinessMarkersByInstallation[$installationId][] = $marker;
        }
    }

    public function hasReadinessMarker(int $installationId, string $marker): bool
    {
        return in_array($marker, $this->readinessMarkersByInstallation[$installationId] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function operationKeysForInstallation(int $installationId): array
    {
        return array_keys($this->operationsByInstallation[$installationId] ?? []);
    }
}
