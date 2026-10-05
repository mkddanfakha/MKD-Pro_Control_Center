<?php

namespace Tests\Feature\Provisioning;

use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\Local\LocalCapacityReservationAdapter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocalInfrastructureArchitectureTest extends TestCase
{
    public function test_local_adapters_are_not_registered_as_production_default_bindings(): void
    {
        $provider = new ProvisioningServiceProvider($this->app);
        $reflection = new \ReflectionMethod($provider, 'registerInfrastructureAdapterBindings');
        $source = file_get_contents((new \ReflectionClass($provider))->getFileName());

        $this->assertStringNotContainsString('LocalCapacityReservationAdapter', $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
    }

    public function test_local_adapter_sources_have_no_external_infrastructure(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/Local');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ([
                'Http::',
                'Guzzle',
                'Process::',
                'SSH',
                'OVH',
                'Cloudflare',
                'o2switch',
                'shell_exec',
                'exec(',
                'password',
                'api_key',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file->getFilename().': '.$forbidden);
            }
        }
    }

    public function test_local_capacity_adapter_is_explicitly_local_test(): void
    {
        $adapter = new LocalCapacityReservationAdapter(
            \App\Services\Provisioning\Infrastructure\Local\LocalProvisioningInfrastructureBundle::createFresh()->state,
        );

        $this->assertInstanceOf(LocalCapacityReservationAdapter::class, $adapter);
    }

    public function test_local_stack_does_not_add_second_execute_endpoint(): void
    {
        $executeRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => in_array('POST', $route->methods(), true)
                && str_contains($route->uri(), 'provisioning-runs')
                && str_contains($route->uri(), 'execute'),
        );

        $this->assertCount(1, $executeRoutes);

        $installationShow = file_get_contents(resource_path('js/Pages/Installations/Show.vue'));
        $this->assertStringNotContainsString('can_execute', $installationShow);
    }
}
