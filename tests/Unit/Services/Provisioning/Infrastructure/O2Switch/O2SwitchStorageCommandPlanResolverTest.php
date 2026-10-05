<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageArtisanCommandPolicy;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageCommandPlanResolver;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;
use Tests\TestCase;

class O2SwitchStorageCommandPlanResolverTest extends TestCase
{
    public function test_storage_link_argv_is_standard_laravel_command(): void
    {
        $this->assertTrue(O2SwitchStorageArtisanCommandPolicy::isStorageLinkArgv([
            'php', 'artisan', 'storage:link',
        ]));
    }

    public function test_resolver_includes_directories_and_link_mapping_when_required(): void
    {
        config()->set('provisioning.gestion.storage_requires_storage_link', true);

        $configuration = new O2SwitchStorageConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );

        $target = new O2SwitchStorageDeploymentTarget(
            installationId: 42,
            applicationRootAbsolutePath: '/home/cpuser/mkd_gestion/installation_42',
            deployRelativeSegment: 'mkd_gestion/installation_42',
            targetFingerprint: 'fp-test',
        );

        $resolved = O2SwitchStorageCommandPlanResolver::resolve($configuration, $target);

        $this->assertNotNull($resolved['plan']);
        $this->assertContains('storage/app/public', $resolved['plan']->requiredWritableRelativeDirectories);
        $this->assertTrue($resolved['plan']->requiresStorageLink);
        $this->assertSame('public/storage', $resolved['plan']->storageLinkMapping['link_relative'] ?? null);
    }

    public function test_resolver_omits_storage_link_when_not_required(): void
    {
        config()->set('provisioning.gestion.storage_requires_storage_link', false);

        $configuration = new O2SwitchStorageConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );

        $target = new O2SwitchStorageDeploymentTarget(
            installationId: 7,
            applicationRootAbsolutePath: '/home/cpuser/mkd_gestion/installation_7',
            deployRelativeSegment: 'mkd_gestion/installation_7',
            targetFingerprint: 'fp-7',
        );

        $resolved = O2SwitchStorageCommandPlanResolver::resolve($configuration, $target);

        $this->assertNotNull($resolved['plan']);
        $this->assertFalse($resolved['plan']->requiresStorageLink);
        $this->assertSame([], $resolved['plan']->storageLinkArtisanArgv);
    }
}
