<?php

namespace Tests\Feature\Provisioning;

use App\Providers\ProvisioningServiceProvider;
use App\Services\AuditLogService;

use App\Services\Provisioning\ProvisioningExecutionAuditService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ProvisioningAuditTrailArchitectureTest extends TestCase
{
    public function test_execution_audit_service_delegates_to_audit_log_not_duplicate_store(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningExecutionAuditService.php'));

        $this->assertStringContainsString(AuditLogService::class, $source);
        $this->assertStringContainsString('ProvisioningAuditPayloadSanitizer', $source);
        $this->assertStringContainsString('provisioning_audit_write_failed', $source);
    }

    public function test_orchestrator_does_not_call_audit_log_service_directly(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningPersistedRunOrchestrator.php'));

        $this->assertStringContainsString('ProvisioningExecutionAuditService', $source);
        $this->assertStringNotContainsString('AuditLogService', $source);
    }

    public function test_no_additional_provisioning_routes_for_audit(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning-runs')
                || str_contains((string) $route->getName(), 'installations.provisioning-runs'),
        );

        $this->assertCount(3, $routes);
    }

    public function test_scheduler_unchanged_for_audit_task(): void
    {
        $provisioningTasks = collect(Schedule::events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'provisioning'));

        $this->assertCount(0, $provisioningTasks);
    }
}
