<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchHealthPlan
{
    /**
     * @param  list<O2SwitchHealthCheckPlanItem>  $checks
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly ?string $applicationPublicBaseUrl,
        public readonly array $checks,
        public readonly O2SwitchStorageDeploymentTarget $deploymentTarget,
        public readonly string $healthPlanFingerprint,
    ) {}

    public function safeOperationLabel(): string
    {
        return 'gestion_application_health';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checksAsSafePlanArray(): array
    {
        return array_map(
            static fn (O2SwitchHealthCheckPlanItem $item): array => $item->toSafePlanArray(),
            $this->checks,
        );
    }

    /**
     * @return list<string>
     */
    public function healthScopeCheckKeys(): array
    {
        return array_values(array_map(
            static fn (O2SwitchHealthCheckPlanItem $item): string => $item->checkKey,
            array_filter(
                $this->checks,
                static fn (O2SwitchHealthCheckPlanItem $item): bool => $item->scope === 'health',
            ),
        ));
    }

    /**
     * @return list<string>
     */
    public function readinessScopeCheckKeys(): array
    {
        return array_values(array_map(
            static fn (O2SwitchHealthCheckPlanItem $item): string => $item->checkKey,
            array_filter(
                $this->checks,
                static fn (O2SwitchHealthCheckPlanItem $item): bool => $item->scope === 'readiness',
            ),
        ));
    }

    public function requiresHttpTarget(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->type === 'http') {
                return true;
            }
        }

        return false;
    }
}
