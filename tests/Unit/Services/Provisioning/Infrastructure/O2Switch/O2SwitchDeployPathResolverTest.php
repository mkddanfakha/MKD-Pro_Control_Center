<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2SwitchDeployPathResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_path_from_installation_id_segment(): void
    {
        $context = $this->makeContext();
        $configuration = $this->configuration('/home/cpuser');

        $path = O2SwitchDeployPathResolver::resolve($context, $configuration);

        $this->assertNotNull($path);
        $this->assertSame(
            '/home/cpuser/mkd_gestion/installation_'.$context->installationId,
            $path->absoluteDeployPath,
        );
    }

    public function test_accepts_explicit_relative_path_from_external_references(): void
    {
        $context = $this->makeContext(externalReferences: [
            'deploy_relative_path' => 'custom/gestion_app',
        ]);
        $configuration = $this->configuration('/home/cpuser');

        $path = O2SwitchDeployPathResolver::resolve($context, $configuration);

        $this->assertNotNull($path);
        $this->assertSame('/home/cpuser/custom/gestion_app', $path->absoluteDeployPath);
    }

    public function test_rejects_path_traversal_in_relative_segment(): void
    {
        $context = $this->makeContext(externalReferences: [
            'deploy_relative_path' => '../escape',
        ]);
        $configuration = $this->configuration('/home/cpuser');

        $this->assertNull(O2SwitchDeployPathResolver::resolve($context, $configuration));
    }

    private function configuration(string $base): O2SwitchDeployConfiguration
    {
        return new O2SwitchDeployConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: $base,
            gestionGitRepositoryUrl: 'https://github.com/mkddanfakha/Gestion.git',
            defaultGitRef: '',
            cpanelHost: '',
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContext(array $externalReferences = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Deploy Path Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Deploy Path Installation',
            'subdomain' => 'path-'.uniqid(),
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
            'target_version' => '1.0.0',
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
