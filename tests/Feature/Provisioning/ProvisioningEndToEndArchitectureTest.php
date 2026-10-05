<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRunStep;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\Cloudflare\NullCloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\NullO2SwitchAdminGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\NullO2SwitchHealthGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\NullO2SwitchModulesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\NullO2SwitchHostingGateway;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableInstallationReadinessPersistenceAdapter;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Garde-fous architecture E2E provisioning — TASK 370.
 */
class ProvisioningEndToEndArchitectureTest extends TestCase
{
    public function test_production_registry_has_eighteen_unique_canonical_steps(): void
    {
        $registry = app(ProvisioningStepRegistry::class);
        $keys = array_map(
            fn ($step) => $step->stepKey(),
            $registry->orderedExecutableSteps(),
        );

        $this->assertCount(18, $keys);
        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_production_bindings_use_null_gateways_and_unavailable_readiness(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullO2SwitchHostingGateway::class, $source);
        $this->assertStringContainsString(NullCloudflareDnsGateway::class, $source);
        $this->assertStringContainsString(NullO2SwitchAdminGateway::class, $source);
        $this->assertStringContainsString(NullO2SwitchModulesGateway::class, $source);
        $this->assertStringContainsString(NullO2SwitchHealthGateway::class, $source);
        $this->assertStringContainsString(UnavailableInstallationReadinessPersistenceAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
    }

    public function test_exactly_three_provisioning_routes_exist(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning-runs')
                || str_contains((string) $route->getName(), 'installations.provisioning-runs'),
        );

        $this->assertCount(3, $routes);
    }

    public function test_scheduler_has_three_subscription_tasks_only(): void
    {
        $subscriptionTasks = collect(Schedule::events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'subscriptions:'));

        $this->assertCount(3, $subscriptionTasks);

        $provisioningTasks = collect(Schedule::events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'provisioning'));

        $this->assertCount(0, $provisioningTasks);
    }

    public function test_provisioning_execution_audit_is_wired_in_orchestrator_not_controller_duplication(): void
    {
        $orchestratorSource = file_get_contents(app_path('Services/Provisioning/ProvisioningPersistedRunOrchestrator.php'));
        $pipelineSource = file_get_contents(app_path('Services/Provisioning/ProvisioningPipeline.php'));

        $this->assertStringContainsString('ProvisioningExecutionAuditService', $orchestratorSource);
        $this->assertStringNotContainsString('AuditLogService', $orchestratorSource);
        $this->assertStringNotContainsString('AuditLogService', $pipelineSource);
    }

    public function test_installation_provisioning_request_creation_is_audited_in_controller(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/InstallationController.php'));

        $this->assertStringContainsString('storeProvisioningRun', $source);
        $this->assertStringContainsString('recordRequestCreated', $source);
        $this->assertStringContainsString('ProvisioningExecutionAuditService', $source);
    }
}
