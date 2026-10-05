<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheCommandPlanResolver;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;
use Tests\TestCase;

class O2SwitchCacheCommandPlanResolverTest extends TestCase
{
    public function test_resolver_builds_plan_with_ordered_cache_commands(): void
    {
        $target = new O2SwitchStorageDeploymentTarget(
            installationId: 11,
            applicationRootAbsolutePath: '/home/cpuser/mkd_gestion/installation_11',
            deployRelativeSegment: 'mkd_gestion/installation_11',
            targetFingerprint: 'fp-cache',
        );

        $resolved = O2SwitchCacheCommandPlanResolver::resolve($target);

        $this->assertNotNull($resolved['plan']);
        $this->assertSame(
            ['config:cache', 'view:cache', 'event:cache'],
            $resolved['plan']->plannedCommandNames(),
        );
        $this->assertSame('database', $resolved['plan']->cacheStore);
    }

    public function test_resolver_rejects_invalid_warmup_steps_in_config(): void
    {
        config()->set('provisioning.gestion.cache_warmup_artisan_steps', [
            ['php', 'artisan', 'cache:clear'],
        ]);

        $target = new O2SwitchStorageDeploymentTarget(
            installationId: 1,
            applicationRootAbsolutePath: '/home/cpuser/mkd_gestion/installation_1',
            deployRelativeSegment: 'mkd_gestion/installation_1',
            targetFingerprint: 'fp',
        );

        $resolved = O2SwitchCacheCommandPlanResolver::resolve($target);

        $this->assertNull($resolved['plan']);
        $this->assertSame('o2switch_cache_not_configured', $resolved['code']);
    }
}
