<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\Exceptions\Provisioning\ProvisioningStepExecutionException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Models\User;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\ProvisioningRunStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\Concerns\BindsLocalProvisioningInfrastructure;
use Tests\Fakes\Provisioning\FakeThrowingStep;
use Tests\TestCase;

class ProvisioningManualExecutionTest extends TestCase
{
    use BindsLocalProvisioningInfrastructure;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_authorized_admin_can_post_execute_on_pending_run(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertRedirect(route('provisioning-runs.show', $run));

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->status);
    }

    public function test_user_without_gate_receives_forbidden_on_execute(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $run = $this->createPendingRunViaFactory($user);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_on_execute(): void
    {
        $run = $this->createPendingRunViaFactory();

        $this->post(route('provisioning-runs.execute', $run))
            ->assertRedirect(route('login'));
    }

    public function test_running_run_execute_is_refused_without_state_change(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertRedirect(route('provisioning-runs.show', $run))
            ->assertSessionHas('error');

        $this->assertSame(ProvisioningRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_succeeded_run_execute_is_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_SUCCEEDED);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertSessionHas('error');
    }

    public function test_failed_run_execute_is_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_FAILED);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertSessionHas('error');
    }

    public function test_manual_intervention_run_execute_is_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo(
            $run->fresh(),
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        );

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertSessionHas('error');
    }

    public function test_cancelled_run_execute_is_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_CANCELLED);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertSessionHas('error');
    }

    public function test_pending_with_empty_registry_is_refused_without_execution(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $emptyRegistry = new ProvisioningStepRegistry;
        $this->app->instance(ProvisioningStepRegistry::class, $emptyRegistry);
        $this->app->forgetInstance(ProvisioningPipeline::class);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertRedirect(route('provisioning-runs.show', $run))
            ->assertSessionHas('error');

        $this->assertSame(ProvisioningRun::STATUS_PENDING, $run->fresh()->status);
    }

    public function test_controller_delegates_to_pipeline_run_persisted(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $mock = Mockery::mock(ProvisioningPipeline::class);
        $mock->shouldReceive('runPersisted')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->is($run)))
            ->andReturn(ProvisioningPipelineResult::manualInterventionRequired(
                \App\DTO\Provisioning\ProvisioningStepResult::manualInterventionRequired(
                    ProvisioningRunStep::STEP_RESERVE,
                    'test',
                    'Test MI',
                ),
                [],
            ));

        $this->app->instance(ProvisioningPipeline::class, $mock);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertRedirect(route('provisioning-runs.show', $run));
    }

    public function test_production_bindings_stop_at_reserve_with_manual_intervention_flash(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $response = $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run));

        $response->assertRedirect(route('provisioning-runs.show', $run))
            ->assertSessionHas('error');

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->status);
        $this->assertNotSame(ProvisioningRun::STATUS_SUCCEEDED, $run->status);

        $reserve = $run->steps()->where('step_key', ProvisioningRunStep::STEP_RESERVE)->sole();
        $this->assertSame(ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED, $reserve->status);
    }

    public function test_second_post_on_same_run_after_execution_is_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $this->actingAs($user)->post(route('provisioning-runs.execute', $run));

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run->fresh()))
            ->assertSessionHas('error');

        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->fresh()->status);
    }

    public function test_step_exception_is_handled_with_safe_flash(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeThrowingStep(
            ProvisioningRunStep::STEP_VALIDATE,
            1,
            new RuntimeException('api_key=secret-token password=abc'),
        ));

        $this->app->instance(ProvisioningStepRegistry::class, $registry);
        $this->app->forgetInstance(ProvisioningPipeline::class);

        $response = $this->actingAs($user)->post(route('provisioning-runs.execute', $run));

        $response->assertSessionHas('error');
        $flash = session('error');
        $this->assertStringNotContainsString('secret-token', (string) $flash);
        $this->assertStringNotContainsString('password=abc', (string) $flash);

        $this->assertSame(ProvisioningRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_show_pending_exposes_can_execute_true(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page
                ->where('execute_actions.can_execute', true)
                ->where('execute_actions.store_url', route('provisioning-runs.execute', $run)));
    }

    public function test_show_running_exposes_can_execute_false(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page
                ->where('execute_actions.can_execute', false));
    }

    public function test_show_succeeded_exposes_can_execute_false(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_SUCCEEDED);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page->where('execute_actions.can_execute', false));
    }

    public function test_show_failed_exposes_can_execute_false(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_FAILED);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page->where('execute_actions.can_execute', false));
    }

    public function test_show_manual_intervention_exposes_can_execute_false(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo(
            $run->fresh(),
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        );

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page->where('execute_actions.can_execute', false));
    }

    public function test_show_cancelled_exposes_can_execute_false(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_CANCELLED);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page->where('execute_actions.can_execute', false));
    }

    public function test_get_execute_is_not_allowed(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createPendingRunViaFactory($user);

        $this->actingAs($user)
            ->get(route('provisioning-runs.execute', $run))
            ->assertMethodNotAllowed();
    }

    public function test_only_one_provisioning_execute_post_route_exists(): void
    {
        $executeRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => in_array('POST', $route->methods(), true)
                && (str_contains((string) $route->getName(), 'execute')
                    || str_contains($route->uri(), '/execute')),
        );

        $this->assertCount(1, $executeRoutes);
        $this->assertSame('provisioning-runs.execute', $executeRoutes->first()->getName());
    }

    private function createPendingRunViaFactory(?User $user = null): ProvisioningRun
    {
        $client = Client::query()->create([
            'company_name' => 'Execute Test Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Execute Installation',
            'subdomain' => 'exec-'.uniqid(),
            'status' => 'active',
        ]);

        return app(ProvisioningRunFactory::class)->createRequest($installation, $user?->id);
    }
}
