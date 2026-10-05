<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\CpanelGitUapiO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\NullO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployAdapter;
use App\Services\Provisioning\Steps\DeployProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchDeployGateway;
use Tests\TestCase;

class O2SwitchDeployArchitectureTest extends TestCase
{
    public function test_o2switch_deploy_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.deploy.enabled'));
        $this->assertFalse(config('provisioning.o2switch.deploy.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_deploy_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchDeployGateway::class, $source);
        $this->assertStringContainsString(O2SwitchDeployAdapter::class, $source);
        $this->assertStringNotContainsString(CpanelGitUapiO2SwitchDeployGateway::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchDeployGateway::class, $source);

        $this->assertInstanceOf(O2SwitchDeployAdapter::class, app(ApplicationDeployAdapter::class));
    }

    public function test_deploy_step_does_not_reference_o2switch_or_git_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(DeployProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('github', strtolower($source));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(ApplicationDeployAdapter::class, $source);
    }

    public function test_adapter_and_null_gateway_contain_no_committed_secrets(): void
    {
        foreach ([
            app_path('Services/Provisioning/Infrastructure/O2Switch/Deploy/O2SwitchDeployAdapter.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Deploy/NullO2SwitchDeployGateway.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Deploy/O2SwitchDeployConfiguration.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('password=', $source, basename($path));
            $this->assertStringNotContainsString('BEGIN OPENSSH', $source, basename($path));
        }
    }

    public function test_cpanel_git_gateway_uses_official_execute_path_only_in_gateway_class(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/Infrastructure/O2Switch/Deploy/CpanelGitUapiO2SwitchDeployGateway.php'));

        $this->assertStringContainsString('/execute/Git', $source);
        $this->assertStringNotContainsString('api.o2switch', $source);
    }

    public function test_deploy_adapter_sources_do_not_reference_dns_database_or_hosting(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Deploy');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('DnsRecordAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('ClientDatabaseAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('HostingSpaceAdapter', $source, $file->getFilename());
        }
    }

    public function test_env_example_documents_o2switch_deploy_flags_without_secret_values(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_DEPLOY_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_DEPLOY_DRY_RUN=false', $example);
        $this->assertStringContainsString('PROVISIONING_GESTION_GIT_REPOSITORY_URL=', $example);
        $this->assertStringNotContainsString('PROVISIONING_O2SWITCH_API_TOKEN=real', $example);
    }
}
