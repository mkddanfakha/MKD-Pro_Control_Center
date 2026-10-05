<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesCommandPlanResolver;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2SwitchDependenciesCommandPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_composer_install_and_npm_ci_plan(): void
    {
        $resolved = O2SwitchDependenciesCommandPlanResolver::resolve(
            $this->makeContext(),
            $this->configuration(),
        );

        $this->assertNotNull($resolved['plan']);
        $this->assertSame([
            'composer',
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--optimize-autoloader',
            '--no-dev',
        ], $resolved['plan']->composerArgv);
        $this->assertSame(['npm', 'ci'], $resolved['plan']->npmArgv);
    }

    public function test_skips_npm_when_node_not_required(): void
    {
        $config = new O2SwitchDependenciesConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            gestionPhpVersionMinimum: '8.2',
            nodeDependenciesRequired: false,
        );

        $resolved = O2SwitchDependenciesCommandPlanResolver::resolve($this->makeContext(), $config);

        $this->assertNotNull($resolved['plan']);
        $this->assertNull($resolved['plan']->npmArgv);
    }

    public function test_detects_php_runtime_incompatibility(): void
    {
        $code = O2SwitchDependenciesCommandPlanResolver::validateRuntimeCompatibility(
            $this->configuration(),
            '8.1.0',
        );

        $this->assertSame('o2switch_dependencies_runtime_incompatible', $code);
    }

    private function configuration(): O2SwitchDependenciesConfiguration
    {
        return new O2SwitchDependenciesConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            gestionPhpVersionMinimum: '8.2',
            nodeDependenciesRequired: true,
        );
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Deps Plan Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Deps Plan Installation',
            'subdomain' => 'deps-'.uniqid(),
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);

        return ProvisioningContext::fromRun($run);
    }
}
