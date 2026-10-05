<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ProvisioningStateArchitectureTest extends TestCase
{
    public function test_state_services_exist_without_provisioning_engine(): void
    {
        $this->assertTrue(class_exists(\App\Services\ProvisioningRunStateService::class));
        $this->assertTrue(class_exists(\App\Services\ProvisioningRunStepStateService::class));

        $this->assertFalse(class_exists(\App\Services\ProvisioningService::class));
        $this->assertFalse(class_exists(\App\Jobs\ProvisioningJob::class));
        $this->assertFalse(class_exists(\App\Http\Controllers\ProvisioningController::class));
        $this->assertFalse(class_exists(\App\Console\Commands\ProvisioningCommand::class));
        $this->assertFalse(class_exists(\App\Services\O2SwitchService::class));
        $this->assertFalse(class_exists(\App\Services\DnsProvisioningService::class));
        $this->assertFalse(class_exists(\App\Services\SshProvisioningClient::class));
    }

    public function test_no_provisioning_routes_or_extra_scheduler_tasks(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning'),
        );
        $this->assertCount(3, $routes);
        $this->assertNotNull($routes->firstWhere(fn ($route) => $route->getName() === 'provisioning-runs.show'));
        $subscriptionTasks = collect(Schedule::events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'subscriptions:'));

        $this->assertCount(3, $subscriptionTasks);

        $provisioningTasks = collect(Schedule::events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'provisioning'));

        $this->assertCount(0, $provisioningTasks);
    }
}
