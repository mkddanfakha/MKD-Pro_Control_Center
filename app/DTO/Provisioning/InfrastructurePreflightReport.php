<?php

namespace App\DTO\Provisioning;

/**
 * Agrégat de préflight infrastructure (TASK 375).
 */
final class InfrastructurePreflightReport
{
    /**
     * @param  list<InfrastructurePreflightCheckResult>  $checks
     * @param  list<string>  $mandatoryServiceKeys
     */
    public function __construct(
        public readonly array $checks,
        public readonly string $globalState,
        public readonly array $mandatoryServiceKeys,
    ) {}

    public static function fromChecks(
        array $checks,
        array $mandatoryServiceKeys,
    ): self {
        $global = InfrastructurePreflightState::GLOBAL_READY;

        foreach ($mandatoryServiceKeys as $serviceKey) {
            $match = self::findCheck($checks, $serviceKey);
            if ($match === null || ! InfrastructurePreflightState::isReady($match->state)) {
                $global = InfrastructurePreflightState::GLOBAL_NOT_READY;

                break;
            }
        }

        return new self($checks, $global, $mandatoryServiceKeys);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'global_state' => $this->globalState,
            'global_ready' => $this->globalState === InfrastructurePreflightState::GLOBAL_READY,
            'mandatory_service_keys' => $this->mandatoryServiceKeys,
            'checks' => array_map(
                static fn (InfrastructurePreflightCheckResult $check) => $check->toPublicArray(),
                $this->checks,
            ),
        ];
    }

    /**
     * @param  list<InfrastructurePreflightCheckResult>  $checks
     */
    private static function findCheck(array $checks, string $serviceKey): ?InfrastructurePreflightCheckResult
    {
        foreach ($checks as $check) {
            if ($check->serviceKey === $serviceKey) {
                return $check;
            }
        }

        return null;
    }
}
