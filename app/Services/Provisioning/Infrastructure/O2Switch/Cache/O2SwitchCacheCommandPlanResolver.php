<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Cache;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchCacheCommandPlanResolver
{
    /**
     * @return array{plan: ?O2SwitchCacheCommandPlan, code: ?string, message: ?string}
     */
    public static function resolve(
        O2SwitchStorageDeploymentTarget $deploymentTarget,
    ): array {
        $steps = O2SwitchCacheArtisanCommandPolicy::productionCacheWarmupSteps();

        if (! O2SwitchCacheArtisanCommandPolicy::assertProductionPlan($steps)) {
            return [
                'plan' => null,
                'code' => 'o2switch_cache_not_configured',
                'message' => 'Plan de cache Artisan invalide ou commande non autorisée.',
            ];
        }

        $cacheStore = (string) config('provisioning.gestion.cache_store_default', 'database');
        $sessionDriver = (string) config('provisioning.gestion.session_driver_default', 'database');
        $queueConnection = (string) config('provisioning.gestion.queue_connection_default', 'database');

        $excluded = O2SwitchCacheArtisanCommandPolicy::excludedArtisanCommandNames();

        $cacheFingerprint = hash('sha256', json_encode([
            'steps' => $steps,
            'excluded' => $excluded,
            'cache_store' => $cacheStore,
            'deployment_target_fingerprint' => $deploymentTarget->targetFingerprint,
        ], JSON_THROW_ON_ERROR));

        return [
            'plan' => new O2SwitchCacheCommandPlan(
                workingDirectory: $deploymentTarget->applicationRootAbsolutePath,
                artisanCommandSteps: $steps,
                deploymentTarget: $deploymentTarget,
                cacheFingerprint: $cacheFingerprint,
                cacheStore: $cacheStore,
                sessionDriver: $sessionDriver,
                queueConnection: $queueConnection,
                excludedArtisanCommands: $excluded,
            ),
            'code' => null,
            'message' => null,
        ];
    }
}
