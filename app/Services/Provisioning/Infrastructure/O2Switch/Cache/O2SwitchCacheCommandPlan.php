<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Cache;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchCacheCommandPlan
{
    /**
     * @param  list<list<string>>  $artisanCommandSteps
     * @param  list<string>  $excludedArtisanCommands
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $artisanCommandSteps,
        public readonly O2SwitchStorageDeploymentTarget $deploymentTarget,
        public readonly string $cacheFingerprint,
        public readonly string $cacheStore,
        public readonly string $sessionDriver,
        public readonly string $queueConnection,
        public readonly array $excludedArtisanCommands,
    ) {}

    public function safeOperationLabel(): string
    {
        return 'laravel_production_cache_warmup';
    }

    /**
     * @return list<string>
     */
    public function plannedCommandNames(): array
    {
        $names = [];
        foreach ($this->artisanCommandSteps as $step) {
            if (isset($step[2]) && is_string($step[2])) {
                $names[] = $step[2];
            }
        }

        return $names;
    }
}
