<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\InstallationModulesAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\NullO2SwitchModulesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesAdapter;
use App\Services\Provisioning\Steps\ModulesProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchModulesGateway;
use Tests\TestCase;

class O2SwitchModulesArchitectureTest extends TestCase
{
    public function test_o2switch_modules_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.modules.enabled'));
        $this->assertFalse(config('provisioning.o2switch.modules.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_modules_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchModulesGateway::class, $source);
        $this->assertStringContainsString(O2SwitchModulesAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchModulesGateway::class, $source);
        $this->assertStringNotContainsString('UnavailableInstallationModulesAdapter', $source);

        $this->assertInstanceOf(O2SwitchModulesAdapter::class, app(InstallationModulesAdapter::class));
    }

    public function test_modules_step_does_not_reference_o2switch_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(ModulesProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(InstallationModulesAdapter::class, $source);
    }

    public function test_env_example_documents_o2switch_modules_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_MODULES_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_MODULES_DRY_RUN=false', $example);
    }

    public function test_gestion_modules_profile_documents_no_remote_toggle(): void
    {
        $this->assertSame([], config('provisioning.gestion.modules_provisioning_artisan_steps'));
        $this->assertSame(
            'none_remote_toggle',
            config('provisioning.gestion.modules_automation_protocol'),
        );
        $this->assertArrayHasKey('notification_center', config('provisioning.gestion.modules_catalog'));
    }

    public function test_modules_adapter_sources_contain_no_http_client(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Modules');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach (['Http::', 'Guzzle', 'DB::', 'mysqli', 'pdo_mysql'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file->getFilename().': '.$forbidden);
            }
        }
    }
}
