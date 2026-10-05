<?php

namespace Tests\Feature\Provisioning;

use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Models\User;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Services\ProvisioningRunStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class ProvisioningRunAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login_on_store(): void
    {
        $installation = $this->makeInstallation();

        $this->post(route('installations.provisioning-runs.store', $installation))
            ->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_to_login_on_show(): void
    {
        $installation = $this->makeInstallation();

        $this->get(route('installations.show', $installation))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_gate_receives_forbidden_on_store(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation))
            ->assertForbidden();
    }

    public function test_user_without_gate_receives_forbidden_on_show(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertForbidden();
    }

    public function test_store_returns_not_found_for_missing_installation(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->post('/installations/999999/provisioning-runs')
            ->assertNotFound();
    }

    public function test_store_without_existing_run_creates_pending_run(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation))
            ->assertRedirect(route('installations.show', $installation));

        $run = ProvisioningRun::query()->where('installation_id', $installation->id)->sole();
        $this->assertSame(ProvisioningRun::STATUS_PENDING, $run->status);
        $this->assertNull($run->started_at);
    }

    public function test_store_creates_eighteen_pending_steps(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation));

        $run = ProvisioningRun::query()->where('installation_id', $installation->id)->sole();

        $steps = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->get();

        $this->assertCount(18, $steps);
        $this->assertTrue($steps->every(fn ($step) => $step->status === ProvisioningRunStep::STATUS_PENDING));
    }

    public function test_store_does_not_invoke_pipeline(): void
    {
        $pipeline = Mockery::mock(ProvisioningPipeline::class);
        $pipeline->shouldNotReceive('runPersisted');
        $this->app->instance(ProvisioningPipeline::class, $pipeline);

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation))
            ->assertRedirect(route('installations.show', $installation));
    }

    public function test_store_redirects_to_installation_show_with_success_flash(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->from(route('installations.show', $installation))
            ->post(route('installations.provisioning-runs.store', $installation))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas(
                'success',
                'Demande de provisioning enregistrée. Aucune exécution automatique n\'a été lancée.',
            );
    }

    public function test_pending_run_blocks_new_store_request_with_error_flash(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas('error');

        $this->assertSame(1, ProvisioningRun::query()->where('installation_id', $installation->id)->count());
    }

    public function test_running_run_blocks_new_store_request(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation->fresh()))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas('error');
    }

    public function test_succeeded_run_blocks_new_store_request(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_SUCCEEDED);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation->fresh()))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas('error');
    }

    public function test_failed_run_allows_new_store_request(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $failed = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        app(ProvisioningRunStateService::class)->transitionTo($failed, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($failed->fresh(), ProvisioningRun::STATUS_FAILED);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation->fresh()))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas('success');

        $this->assertSame(2, ProvisioningRun::query()->where('installation_id', $installation->id)->count());
    }

    public function test_manual_intervention_run_allows_new_store_request(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $prior = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        app(ProvisioningRunStateService::class)->transitionTo($prior, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo(
            $prior->fresh(),
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        );

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation->fresh()))
            ->assertSessionHas('success');

        $this->assertSame(2, ProvisioningRun::query()->where('installation_id', $installation->id)->count());
    }

    public function test_cancelled_run_allows_new_store_request(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $prior = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        app(ProvisioningRunStateService::class)->transitionTo($prior, ProvisioningRun::STATUS_CANCELLED);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation->fresh()))
            ->assertSessionHas('success');

        $this->assertSame(2, ProvisioningRun::query()->where('installation_id', $installation->id)->count());
    }

    public function test_retry_run_sets_retry_of_run_id(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $failed = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        app(ProvisioningRunStateService::class)->transitionTo($failed, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($failed->fresh(), ProvisioningRun::STATUS_FAILED);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation->fresh()));

        $retry = ProvisioningRun::query()
            ->where('installation_id', $installation->id)
            ->orderByDesc('id')
            ->first();

        $this->assertSame($failed->id, $retry->retry_of_run_id);
    }

    public function test_show_exposes_provisioning_actions_and_last_run(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Show')
                ->where('provisioning_actions.can_create_request', true)
                ->where('last_provisioning_run', null)
                ->has('provisioning_actions.store_url'));
    }

    public function test_show_exposes_last_run_after_creation(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('last_provisioning_run.id', $run->id)
                ->where('last_provisioning_run.status', ProvisioningRun::STATUS_PENDING)
                ->where('last_provisioning_run.steps_total', 18)
                ->where('provisioning_actions.can_create_request', false));
    }

    public function test_show_can_create_request_false_when_pending(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provisioning_actions.can_create_request', false)
                ->whereType('provisioning_actions.unavailable_reason', 'string'));
    }

    public function test_store_does_not_modify_installation_record(): void
    {
        $this->mockPipelineMustNotRun();

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Nom stable']);
        $snapshot = $installation->fresh()->only([
            'name',
            'subdomain',
            'status',
            'client_id',
            'updated_at',
        ]);

        $this->actingAs($user)
            ->post(route('installations.provisioning-runs.store', $installation));

        $after = $installation->fresh()->only([
            'name',
            'subdomain',
            'status',
            'client_id',
            'updated_at',
        ]);

        $this->assertSame($snapshot['name'], $after['name']);
        $this->assertSame($snapshot['subdomain'], $after['subdomain']);
        $this->assertSame($snapshot['status'], $after['status']);
        $this->assertSame($snapshot['client_id'], $after['client_id']);
    }

    public function test_installation_controller_does_not_reference_pipeline_execution(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/InstallationController.php'));

        $this->assertStringNotContainsString('runPersisted', $source);
        $this->assertStringNotContainsString('ProvisioningPipeline', $source);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstallation(array $attributes = []): Installation
    {
        $clientId = $attributes['client_id'] ?? Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ])->id;
        unset($attributes['client_id']);

        return Installation::query()->create(array_merge([
            'client_id' => $clientId,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ], $attributes));
    }

    private function mockPipelineMustNotRun(): void
    {
        $pipeline = Mockery::mock(ProvisioningPipeline::class);
        $pipeline->shouldNotReceive('runPersisted');
        $this->app->instance(ProvisioningPipeline::class, $pipeline);
    }
}
