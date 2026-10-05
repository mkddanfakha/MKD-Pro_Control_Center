<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\NullO2SwitchAdminGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminAdapter;
use App\Services\Provisioning\Steps\AdminProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchAdminGateway;
use Tests\TestCase;

class O2SwitchAdminArchitectureTest extends TestCase
{
    public function test_o2switch_admin_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.admin.enabled'));
        $this->assertFalse(config('provisioning.o2switch.admin.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_admin_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchAdminGateway::class, $source);
        $this->assertStringContainsString(O2SwitchAdminAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchAdminGateway::class, $source);

        $this->assertInstanceOf(O2SwitchAdminAdapter::class, app(AdminBootstrapAdapter::class));
    }

    public function test_admin_step_does_not_reference_o2switch_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(AdminProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(AdminBootstrapAdapter::class, $source);
    }

    public function test_env_example_documents_o2switch_admin_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_ADMIN_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_ADMIN_DRY_RUN=false', $example);
    }

    public function test_gestion_admin_profile_documents_no_unattended_artisan_create(): void
    {
        $this->assertSame([], config('provisioning.gestion.admin_bootstrap_artisan_steps'));
        $this->assertFalse(config('provisioning.gestion.fortify_public_registration_enabled'));
        $this->assertSame('email', config('provisioning.gestion.admin_unique_identity_field'));
    }
}
