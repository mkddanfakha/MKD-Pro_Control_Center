<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\NullO2SwitchHealthGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthAdapter;
use App\Services\Provisioning\Steps\HealthProvisioningStep;
use App\Services\Provisioning\Steps\MarkReadyProvisioningStep;
use App\Services\Provisioning\Steps\MarkVerifiedProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchHealthGateway;
use Tests\TestCase;

class O2SwitchHealthArchitectureTest extends TestCase
{
    public function test_o2switch_health_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.health.enabled'));
        $this->assertFalse(config('provisioning.o2switch.health.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_health_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchHealthGateway::class, $source);
        $this->assertStringContainsString(O2SwitchHealthAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchHealthGateway::class, $source);
        $this->assertStringNotContainsString('UnavailableApplicationHealthAdapter', $source);

        $this->assertInstanceOf(O2SwitchHealthAdapter::class, app(ApplicationHealthAdapter::class));
    }

    public function test_health_step_does_not_reference_o2switch_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(HealthProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(ApplicationHealthAdapter::class, $source);
    }

    public function test_readiness_steps_do_not_reference_health_adapter(): void
    {
        foreach ([MarkVerifiedProvisioningStep::class, MarkReadyProvisioningStep::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            $this->assertStringNotContainsString('O2SwitchHealth', $source);
            $this->assertStringNotContainsString('ApplicationHealthAdapter', $source);
        }
    }

    public function test_env_example_documents_o2switch_health_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_HEALTH_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_HEALTH_DRY_RUN=false', $example);
    }

    public function test_gestion_health_profile_documents_laravel_up_route(): void
    {
        $this->assertSame('/up', config('provisioning.gestion.health_laravel_up_path'));
        $this->assertNotSame([], config('provisioning.gestion.health_check_definitions'));
    }

    public function test_health_adapter_sources_contain_no_http_client(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Health');

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
