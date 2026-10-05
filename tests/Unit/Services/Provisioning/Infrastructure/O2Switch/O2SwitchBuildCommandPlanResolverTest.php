<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildCommandPlanResolver;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2SwitchBuildCommandPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_npm_run_build_plan(): void
    {
        $resolved = O2SwitchBuildCommandPlanResolver::resolve(
            $this->makeContext(),
            $this->configuration(),
        );

        $this->assertNotNull($resolved['plan']);
        $this->assertSame(['npm', 'run', 'build'], $resolved['plan']->npmBuildArgv);
        $this->assertContains('public/build/manifest.json', $resolved['plan']->expectedArtifactRelativePaths);
    }

    public function test_detects_missing_vite_public_keys_for_live_build(): void
    {
        $missing = O2SwitchBuildCommandPlanResolver::missingRequiredPublicViteKeys($this->makeContext());

        $this->assertSame(['VITE_APP_NAME'], $missing);
    }

    public function test_accepts_declared_vite_public_keys(): void
    {
        $context = $this->makeContext(externalReferences: [
            'vite_public_env_keys_present' => ['VITE_APP_NAME'],
        ]);

        $this->assertSame([], O2SwitchBuildCommandPlanResolver::missingRequiredPublicViteKeys($context));
    }

    private function configuration(): O2SwitchBuildConfiguration
    {
        return new O2SwitchBuildConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            npmBuildScript: 'build',
            artifactManifestRelativePath: 'public/build/manifest.json',
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContext(array $externalReferences = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Build Plan Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Build Plan Installation',
            'subdomain' => 'build-'.uniqid(),
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);

        return new ProvisioningContext(
            provisioningRun: $run,
            installation: $installation,
            targetVersion: $run->target_version,
            targetCommit: $run->target_commit,
            pipelineVersion: $run->pipeline_version,
            externalReferences: $externalReferences,
        );
    }
}
