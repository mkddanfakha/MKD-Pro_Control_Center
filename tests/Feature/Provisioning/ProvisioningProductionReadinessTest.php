<?php

namespace Tests\Feature\Provisioning;

use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningRunFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MocksProvisioningExecutionReadiness;
use Tests\TestCase;

/**
 * Comportement production nominal (sans infra réelle) — TASK 372.
 */
class ProvisioningProductionReadinessTest extends TestCase
{
    use MocksProvisioningExecutionReadiness;
    use RefreshDatabase;

    public function test_production_execute_stops_at_reserve_with_manual_intervention(): void
    {
        $this->mockProvisioningExecutionReadinessReady();

        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Readiness Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Readiness Install',
            'subdomain' => 'ready-'.uniqid(),
            'domain' => 'client.example.test',
            'database_name' => 'mkd_ready',
            'database_host' => 'mysql.internal.test',
            'status' => 'active',
        ]);

        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $this->actingAs($user)
            ->post(route('provisioning-runs.execute', $run))
            ->assertRedirect(route('provisioning-runs.show', $run));

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->status);

        $validate = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_VALIDATE)
            ->first();
        $reserve = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_RESERVE)
            ->first();
        $dns = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_DNS)
            ->first();

        $this->assertSame(ProvisioningRunStep::STATUS_SUCCEEDED, $validate?->status);
        $this->assertSame(ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED, $reserve?->status);
        $this->assertSame(ProvisioningRunStep::STATUS_PENDING, $dns?->status);
    }

    public function test_failed_validate_prevents_later_steps_from_running(): void
    {
        $client = Client::query()->create([
            'company_name' => 'X',
            'contact_name' => 'Y',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Valid Name',
            'subdomain' => '',
            'domain' => 'client.example.test',
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        app(ProvisioningPipeline::class)->runPersisted($run);

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->status);

        $validateStatus = ProvisioningRunStep::query()
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_VALIDATE)
            ->value('status');

        $this->assertContains($validateStatus, [
            ProvisioningRunStep::STATUS_FAILED,
            ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ]);

        $this->assertSame(
            ProvisioningRunStep::STATUS_PENDING,
            ProvisioningRunStep::query()
                ->where('provisioning_run_id', $run->id)
                ->where('step_key', ProvisioningRunStep::STEP_RESERVE)
                ->value('status'),
        );
    }
}
