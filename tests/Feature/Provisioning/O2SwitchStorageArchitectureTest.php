<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\NullO2SwitchStorageGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageAdapter;
use App\Services\Provisioning\Steps\StorageProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchStorageGateway;
use Tests\TestCase;

class O2SwitchStorageArchitectureTest extends TestCase
{
    public function test_o2switch_storage_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.storage.enabled'));
        $this->assertFalse(config('provisioning.o2switch.storage.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_storage_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchStorageGateway::class, $source);
        $this->assertStringContainsString(O2SwitchStorageAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchStorageGateway::class, $source);

        $this->assertInstanceOf(O2SwitchStorageAdapter::class, app(StorageSetupAdapter::class));
    }

    public function test_storage_step_does_not_reference_o2switch_or_shell_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(StorageProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('chmod', strtolower($source));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(StorageSetupAdapter::class, $source);
    }

    public function test_env_example_documents_o2switch_storage_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_STORAGE_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_STORAGE_DRY_RUN=false', $example);
    }

    public function test_gestion_profile_documents_s3_as_optional_external_only(): void
    {
        $this->assertContains('s3', config('provisioning.gestion.optional_external_filesystem_disks'));
        $this->assertContains('media', config('provisioning.gestion.application_filesystem_disks'));
    }
}
