<?php

namespace Tests\Feature\Provisioning;

use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Models\User;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningRunFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class ProvisioningRunShowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $run = $this->createRunWithSteps();

        $this->get(route('provisioning-runs.show', $run))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_gate_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $run = $this->createRunWithSteps();

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertForbidden();
    }

    public function test_show_returns_not_found_for_missing_run(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get('/provisioning-runs/999999')
            ->assertNotFound();
    }

    public function test_authorized_user_receives_show_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProvisioningRuns/Show')
                ->where('provisioning_run.id', $run->id));
    }

    public function test_show_exposes_installation_and_client(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Acme Provisioning',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Site Acme',
            'subdomain' => 'acme-'.uniqid(),
            'status' => 'active',
        ]);
        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provisioning_run.installation.id', $installation->id)
                ->where('provisioning_run.installation.name', 'Site Acme')
                ->where('provisioning_run.client.company_name', 'Acme Provisioning'));
    }

    public function test_show_exposes_run_status_and_dates(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps(['status' => ProvisioningRun::STATUS_PENDING]);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provisioning_run.status', ProvisioningRun::STATUS_PENDING)
                ->whereType('provisioning_run.created_at', 'string')
                ->whereType('provisioning_run.requested_at', 'string'));
    }

    public function test_show_exposes_retry_of_run_id_when_set(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $parent = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);
        $parent->update(['status' => ProvisioningRun::STATUS_FAILED, 'finished_at' => now()]);

        $child = app(ProvisioningRunFactory::class)->createRequest($installation->fresh(), $user->id);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $child))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provisioning_run.retry_of_run_id', $parent->id)
                ->where('navigation.retry_parent_show', route('provisioning-runs.show', $parent)));
    }

    public function test_show_without_retry_has_no_parent_navigation_link(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provisioning_run.retry_of_run_id', null)
                ->where('navigation.retry_parent_show', null));
    }

    public function test_step_statistics_are_correct(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->orderBy('step_order')
            ->limit(2)
            ->update(['status' => ProvisioningRunStep::STATUS_SUCCEEDED]);

        ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->orderBy('step_order')
            ->skip(2)
            ->limit(1)
            ->update(['status' => ProvisioningRunStep::STATUS_FAILED]);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run->fresh()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('step_statistics.total', 18)
                ->where('step_statistics.succeeded', 2)
                ->where('step_statistics.failed', 1)
                ->where('step_statistics.pending', 15));
    }

    public function test_steps_are_ordered_by_step_order(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        $response = $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('steps', 18)
            ->where('steps.0.step_order', 1)
            ->where('steps.0.step_key', ProvisioningRunStep::STEP_VALIDATE));

        $orders = collect($response->original->getData()['page']['props']['steps'] ?? [])
            ->pluck('step_order')
            ->all();

        $this->assertSame(range(1, 18), $orders);
    }

    public function test_step_status_and_message_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        $step = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_VALIDATE)
            ->first();

        $step->update([
            'status' => ProvisioningRunStep::STATUS_FAILED,
            'error_code' => 'VALIDATION_FAILED',
            'error_message' => 'Champ subdomain invalide',
            'output_summary' => ['code' => 'ok', 'detail' => 'visible'],
        ]);

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run->fresh()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('steps.0.status', ProvisioningRunStep::STATUS_FAILED)
                ->where('steps.0.error_code', 'VALIDATION_FAILED')
                ->where('steps.0.message', 'Champ subdomain invalide')
                ->whereType('steps.0.output_summary', 'string'));
    }

    public function test_sensitive_step_metadata_is_not_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_VALIDATE)
            ->update([
                'metadata' => [
                    'api_token' => 'secret-value',
                    'note' => 'visible',
                ],
                'output_summary' => [
                    'password' => 'must-hide',
                    'label' => 'ok',
                ],
            ]);

        $response = $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run->fresh()));

        $payload = json_encode($response->original->getData()['page']['props'] ?? []);

        $this->assertStringNotContainsString('secret-value', (string) $payload);
        $this->assertStringNotContainsString('must-hide', (string) $payload);
        $this->assertStringNotContainsString('api_token', (string) $payload);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('steps.0.output_summary', '{"label":"ok"}'));
    }

    public function test_get_does_not_invoke_pipeline(): void
    {
        $pipeline = Mockery::mock(ProvisioningPipeline::class);
        $pipeline->shouldNotReceive('runPersisted');
        $this->app->instance(ProvisioningPipeline::class, $pipeline);

        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run))
            ->assertOk();
    }

    public function test_get_does_not_modify_run_or_steps(): void
    {
        $user = $this->controlCenterAdminUser();
        $run = $this->createRunWithSteps();

        $runSnapshot = [
            'status' => $run->fresh()->status,
            'updated_at' => $run->fresh()->updated_at?->format('Y-m-d H:i:s.u'),
            'started_at' => $run->fresh()->started_at?->format('Y-m-d H:i:s.u'),
            'finished_at' => $run->fresh()->finished_at?->format('Y-m-d H:i:s.u'),
        ];
        $stepSnapshot = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->orderBy('id')
            ->get(['id', 'status', 'updated_at'])
            ->map(fn (ProvisioningRunStep $step) => [
                'id' => $step->id,
                'status' => $step->status,
                'updated_at' => $step->updated_at?->format('Y-m-d H:i:s.u'),
            ])
            ->all();

        $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run));

        $runAfter = [
            'status' => $run->fresh()->status,
            'updated_at' => $run->fresh()->updated_at?->format('Y-m-d H:i:s.u'),
            'started_at' => $run->fresh()->started_at?->format('Y-m-d H:i:s.u'),
            'finished_at' => $run->fresh()->finished_at?->format('Y-m-d H:i:s.u'),
        ];
        $this->assertSame($runSnapshot, $runAfter);

        $stepAfter = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->orderBy('id')
            ->get(['id', 'status', 'updated_at'])
            ->map(fn (ProvisioningRunStep $step) => [
                'id' => $step->id,
                'status' => $step->status,
                'updated_at' => $step->updated_at?->format('Y-m-d H:i:s.u'),
            ])
            ->all();

        $this->assertSame($stepSnapshot, $stepAfter);
    }

    public function test_installation_show_includes_link_to_provisioning_run(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('last_provisioning_run.id', $run->id)
                ->where('last_provisioning_run.show_url', route('provisioning-runs.show', $run)));
    }

    public function test_provisioning_routes_are_store_show_and_execute_only(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning'),
        );

        $this->assertCount(3, $routes);

        $methodsByName = $routes->mapWithKeys(fn ($route) => [
            (string) $route->getName() => $route->methods(),
        ]);

        $this->assertSame(['GET', 'HEAD'], $methodsByName['provisioning-runs.show']);
        $this->assertContains('POST', $methodsByName['installations.provisioning-runs.store']);
        $this->assertContains('POST', $methodsByName['provisioning-runs.execute']);
        $this->assertNotContains('PUT', $methodsByName->flatten()->all());
        $this->assertNotContains('PATCH', $methodsByName->flatten()->all());
        $this->assertNotContains('DELETE', $methodsByName->flatten()->all());
    }

    public function test_provisioning_run_controller_does_not_mutate_state_directly(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ProvisioningRunController.php'));

        foreach ([
            'ProvisioningPersistedRunOrchestrator',
            'ProvisioningRunStateService',
            'ProvisioningRunStepStateService',
            'lockForUpdate',
            '->update(',
            '->create(',
            '->delete(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }

        $this->assertStringContainsString('runPersisted', $source);
    }

    /**
     * @param  array<string, mixed>  $runAttributes
     */
    private function createRunWithSteps(array $runAttributes = []): ProvisioningRun
    {
        $requestUser = User::factory()->create();
        $installation = $this->makeInstallation();

        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $requestUser->id);

        if ($runAttributes !== []) {
            $run->update($runAttributes);
        }

        return $run->fresh();
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
}
