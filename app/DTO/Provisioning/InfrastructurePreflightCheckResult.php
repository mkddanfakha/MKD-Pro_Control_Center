<?php

namespace App\DTO\Provisioning;

use App\Support\Provisioning\ProvisioningSecretSanitizer;
use InvalidArgumentException;

/**
 * Résultat d'un contrôle de préflight — aucun secret (TASK 375).
 */
final class InfrastructurePreflightCheckResult
{
    /** @var array<string, mixed>|null */
    public readonly ?array $diagnostics;

    /**
     * @param  array<string, mixed>|null  $diagnostics
     */
    public function __construct(
        public readonly string $serviceKey,
        public readonly string $serviceLabel,
        public readonly string $capability,
        public readonly string $state,
        public readonly string $code,
        public readonly string $operatorMessage,
        public readonly bool $provisioningFeatureEnabled,
        public readonly bool $configuredAndReachable,
        ?array $diagnostics = null,
    ) {
        if (! in_array($state, InfrastructurePreflightState::terminalStates(), true)) {
            throw new InvalidArgumentException('État de préflight inconnu : '.$state);
        }

        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage($operatorMessage);

        $this->diagnostics = $diagnostics === null
            ? null
            : ProvisioningSecretSanitizer::sanitizeArrayForExposure($diagnostics);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return array_filter([
            'service_key' => $this->serviceKey,
            'service_label' => $this->serviceLabel,
            'capability' => $this->capability,
            'state' => $this->state,
            'code' => $this->code,
            'message' => $this->operatorMessage,
            'provisioning_feature_enabled' => $this->provisioningFeatureEnabled,
            'configured_and_reachable' => $this->configuredAndReachable,
            'diagnostics' => $this->diagnostics,
        ], fn ($value) => $value !== null);
    }

}
