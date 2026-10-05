<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\NullO2SwitchMigrateGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateAdapter;
use App\Services\Provisioning\Steps\MigrateProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchMigrateGateway;
use Tests\TestCase;

class O2SwitchMigrateArchitectureTest extends TestCase
{
    public function test_o2switch_migrate_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.migrate.enabled'));
        $this->assertFalse(config('provisioning.o2switch.migrate.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_migrate_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchMigrateGateway::class, $source);
        $this->assertStringContainsString(O2SwitchMigrateAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchMigrateGateway::class, $source);

        $this->assertInstanceOf(O2SwitchMigrateAdapter::class, app(DatabaseMigrationAdapter::class));
    }

    public function test_migrate_step_does_not_reference_o2switch_or_artisan_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(MigrateProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('migrate:fresh', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(DatabaseMigrationAdapter::class, $source);
    }

    public function test_adapter_and_null_gateway_contain_no_committed_secrets(): void
    {
        foreach ([
            app_path('Services/Provisioning/Infrastructure/O2Switch/Migrate/O2SwitchMigrateAdapter.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Migrate/NullO2SwitchMigrateGateway.php'),
            app_path('Services/Provisioning/Infrastructure/O2Switch/Migrate/O2SwitchMigrateConfiguration.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('password=', $source, basename($path));
        }
    }

    public function test_migrate_sources_do_not_reference_other_infrastructure_adapters(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch/Migrate');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('ApplicationBuildAdapter', $source, $file->getFilename());
            $this->assertStringNotContainsString('EnvironmentConfigurationAdapter', $source, $file->getFilename());
        }
    }

    public function test_env_example_documents_o2switch_migrate_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_MIGRATE_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_MIGRATE_DRY_RUN=false', $example);
    }

    public function test_gestion_migration_argv_configured_as_migrate_force(): void
    {
        $this->assertSame(
            ['php', 'artisan', 'migrate', '--force'],
            config('provisioning.gestion.migration_artisan_argv'),
        );
    }
}
