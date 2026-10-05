<?php

namespace Tests\Feature\Provisioning;

use App\Providers\ProvisioningServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ProvisioningManualExecutionArchitectureTest extends TestCase
{
    public function test_exactly_one_post_provisioning_execute_route_with_gate(): void
    {
        $executeRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => in_array('POST', $route->methods(), true)
                && (str_contains((string) $route->getName(), 'execute')
                    || str_contains($route->uri(), 'provisioning-runs/{provisioning_run}/execute')),
        );

        $this->assertCount(1, $executeRoutes);

        $route = $executeRoutes->first();
        $this->assertSame('provisioning-runs.execute', $route->getName());
        $this->assertContains('can:accessControlCenter', $route->middleware());
    }

    public function test_provisioning_routes_count_is_three(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning'),
        );

        $this->assertCount(3, $routes);
        $this->assertSame(
            [
                'installations.provisioning-runs.store',
                'provisioning-runs.execute',
                'provisioning-runs.show',
            ],
            $routes->map(fn ($route) => $route->getName())->sort()->values()->all(),
        );
    }

    public function test_no_get_execute_route(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => in_array('GET', $route->methods(), true)
                && str_contains($route->uri(), 'provisioning-runs')
                && str_contains($route->uri(), 'execute'),
        );

        $this->assertCount(0, $routes);
    }

    public function test_no_provisioning_scheduler_entries(): void
    {
        $provisioningSchedule = collect(Schedule::events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn (string $c) => str_contains(strtolower($c), 'provisioning'));

        $this->assertCount(0, $provisioningSchedule);
    }

    public function test_provisioning_run_controller_is_thin_and_has_no_infrastructure(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ProvisioningRunController.php'));

        $this->assertStringContainsString('function execute', $source);
        $this->assertStringContainsString('runPersisted', $source);
        $this->assertStringNotContainsString('lockForUpdate', $source);

        foreach (['o2switch', 'O2Switch', 'SSH', 'Http::', 'Guzzle', 'Infrastructure\\Local\\'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }

        foreach ([
            'ProvisioningRunStateService',
            'ProvisioningRunStepStateService',
            'ProvisioningPersistedRunOrchestrator',
            '->transitionTo(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    public function test_local_adapters_are_not_bound_in_production_provider(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
    }

    public function test_run_show_exposes_execute_actions_from_server(): void
    {
        $source = file_get_contents(resource_path('js/Pages/ProvisioningRuns/Show.vue'));
        $this->assertStringContainsString('execute_actions', $source);
        $this->assertStringContainsString('can_execute', $source);
    }

    public function test_installation_show_does_not_expose_execute_actions(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Installations/Show.vue'));
        $this->assertStringNotContainsString('can_execute', $source);
    }
}
