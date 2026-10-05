<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\NullO2SwitchDependenciesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesAdapter;
use App\Services\Provisioning\Steps\DependenciesProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchDependenciesGateway;
use Tests\TestCase;

class O2SwitchDependenciesArchitectureTest extends TestCase
{
    public function test_o2switch_dependencies_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.dependencies.enabled'));
        $this->assertFalse(config('provisioning.o2switch.dependencies.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_dependencies_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchDependenciesGateway::class, $source);
        $this->assertStringContainsString(O2SwitchDependenciesAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchDependenciesGateway::class, $source);

        $this->assertInstanceOf(O2SwitchDependenciesAdapter::class, app(DependencyInstallationAdapter::class));
    }

    public function test_dependencies_step_does_not_reference_o2switch_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(DependenciesProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('composer', strtolower($source));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(DependencyInstallationAdapter::class, $source);
    }

    public function test_adapter_and_null_gateway_contain_no_committed_secrets(): void
    {
        foreach ([
            app_path('Services/Provisioning/Infrastructure/O2Switch/Dependencies/O2SwitchDependenciesAdapter.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Dependencies/NullO2SwitchDependenciesGateway.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Dependencies/O2SwitchDependenciesConfiguration.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('password=', $source, basename($path));
        }
    }

    public function test_dependencies_sources_do_not_reference_other_infrastructure_adapters(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Dependencies');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('EnvironmentConfigurationAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('ApplicationDeployAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('ClientDatabaseAdapter', $source, $file->getFilename());
        }
    }

    public function test_env_example_documents_o2switch_dependencies_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_DEPENDENCIES_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_DEPENDENCIES_DRY_RUN=false', $example);
    }
}
