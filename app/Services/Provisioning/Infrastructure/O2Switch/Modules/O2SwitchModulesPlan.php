<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Modules;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchModulesPlan
{
    /**
     * @param  list<string>  $requestedModuleIds
     * @param  list<string>  $noopModuleIds
     * @param  list<string>  $logicalOperations
     * @param  list<list<string>>  $artisanCommandSteps
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $requestedModuleIds,
        public readonly array $noopModuleIds,
        public readonly array $logicalOperations,
        public readonly array $artisanCommandSteps,
        public readonly O2SwitchStorageDeploymentTarget $deploymentTarget,
        public readonly string $modulesPlanFingerprint,
    ) {}

    public function safeOperationLabel(): string
    {
        return 'gestion_installation_modules';
    }

    /**
     * @return list<string>
     */
    public function plannedArtisanCommandNames(): array
    {
        $names = [];

        foreach ($this->artisanCommandSteps as $step) {
            if (isset($step[2]) && is_string($step[2])) {
                $names[] = $step[2];
            }
        }

        return $names;
    }

    public function isEmpty(): bool
    {
        return $this->requestedModuleIds === []
            && $this->logicalOperations === []
            && $this->artisanCommandSteps === [];
    }
}
