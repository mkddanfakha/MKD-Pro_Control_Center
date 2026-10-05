<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\CpanelUapiO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\NullO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseAdapter;
use App\Services\Provisioning\Steps\DatabaseProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchDatabaseGateway;
use Tests\TestCase;

class O2SwitchDatabaseArchitectureTest extends TestCase
{
    public function test_o2switch_database_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.database.enabled'));
        $this->assertFalse(config('provisioning.o2switch.database.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_database_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchDatabaseGateway::class, $source);
        $this->assertStringContainsString(O2SwitchDatabaseAdapter::class, $source);
        $this->assertStringNotContainsString(CpanelUapiO2SwitchDatabaseGateway::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchDatabaseGateway::class, $source);

        $this->assertInstanceOf(O2SwitchDatabaseAdapter::class, app(ClientDatabaseAdapter::class));
    }

    public function test_database_step_does_not_reference_o2switch_or_cpanel_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(DatabaseProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('cpanel', strtolower($source));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(ClientDatabaseAdapter::class, $source);
    }

    public function test_adapter_and_null_gateway_contain_no_committed_secrets(): void
    {
        foreach ([
            app_path('Services/Provisioning/Infrastructure/O2Switch/Database/O2SwitchDatabaseAdapter.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Database/NullO2SwitchDatabaseGateway.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Database/O2SwitchDatabaseConfiguration.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('password=', $source, basename($path));
            $this->assertStringNotContainsString('Bearer sk_', $source, basename($path));
        }
    }

    public function test_cpanel_uapi_gateway_uses_official_execute_path_only_in_gateway_class(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/Infrastructure/O2Switch/Database/CpanelUapiO2SwitchDatabaseGateway.php'));

        $this->assertStringContainsString('/execute/Mysql', $source);
        $this->assertStringNotContainsString('api.o2switch', $source);
    }

    public function test_database_adapter_sources_do_not_reference_dns_or_deploy(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Database');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('DnsRecordAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('ApplicationDeployAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('HostingSpaceAdapter', $source, $file->getFilename());
        }
    }

    public function test_env_example_documents_o2switch_database_flags_without_secret_values(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_DATABASE_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_DATABASE_DRY_RUN=false', $example);
        $this->assertStringNotContainsString('PROVISIONING_O2SWITCH_API_TOKEN=real', $example);
    }
}
