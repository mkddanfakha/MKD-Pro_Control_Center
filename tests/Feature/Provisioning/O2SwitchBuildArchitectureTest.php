<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\NullO2SwitchBuildGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildAdapter;
use App\Services\Provisioning\Steps\BuildProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchBuildGateway;
use Tests\TestCase;

class O2SwitchBuildArchitectureTest extends TestCase
{
    public function test_o2switch_build_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.build.enabled'));
        $this->assertFalse(config('provisioning.o2switch.build.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_build_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchBuildGateway::class, $source);
        $this->assertStringContainsString(O2SwitchBuildAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchBuildGateway::class, $source);

        $this->assertInstanceOf(O2SwitchBuildAdapter::class, app(ApplicationBuildAdapter::class));
    }

    public function test_build_step_does_not_reference_o2switch_or_npm_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(BuildProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('npm', strtolower($source));
        $this->assertStringNotContainsString('vite', strtolower($source));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(ApplicationBuildAdapter::class, $source);
    }

    public function test_adapter_and_null_gateway_contain_no_committed_secrets(): void
    {
        foreach ([
            app_path('Services/Provisioning/Infrastructure/O2Switch/Build/O2SwitchBuildAdapter.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Build/NullO2SwitchBuildGateway.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Build/O2SwitchBuildConfiguration.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('password=', $source, basename($path));
        }
    }

    public function test_build_sources_do_not_reference_other_infrastructure_adapters(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Build');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('DependencyInstallationAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('EnvironmentConfigurationAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('ApplicationDeployAdapter', $source, $file->getFilename());
        }
    }

    public function test_env_example_documents_o2switch_build_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_BUILD_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_BUILD_DRY_RUN=false', $example);
    }
}
