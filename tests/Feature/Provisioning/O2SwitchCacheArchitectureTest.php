<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\NullO2SwitchCacheGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheAdapter;
use App\Services\Provisioning\Steps\CacheProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchCacheGateway;
use Tests\TestCase;

class O2SwitchCacheArchitectureTest extends TestCase
{
    public function test_o2switch_cache_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.cache.enabled'));
        $this->assertFalse(config('provisioning.o2switch.cache.dry_run'));
    }

    public function test_production_binds_null_gateway_and_o2switch_cache_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchCacheGateway::class, $source);
        $this->assertStringContainsString(O2SwitchCacheAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeO2SwitchCacheGateway::class, $source);

        $this->assertInstanceOf(O2SwitchCacheAdapter::class, app(CacheWarmupAdapter::class));
    }

    public function test_cache_step_does_not_reference_o2switch_or_artisan_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(CacheProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('optimize', strtolower($source));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(CacheWarmupAdapter::class, $source);
    }

    public function test_env_example_documents_o2switch_cache_flags(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_CACHE_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_CACHE_DRY_RUN=false', $example);
    }

    public function test_gestion_cache_profile_uses_database_store_and_excludes_route_cache(): void
    {
        $this->assertSame('database', config('provisioning.gestion.cache_store_default'));
        $this->assertContains('route:cache', config('provisioning.gestion.cache_excluded_artisan_commands'));
        $this->assertNotContains('route:cache', config('provisioning.gestion.cache_execution_order'));
    }
}
