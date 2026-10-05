<?php

namespace Tests\Unit\Services\Provisioning;

use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\ProvisioningRunStateService;
use BadMethodCallException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProvisioningPipelineTest extends TestCase
{
    use RefreshDatabase;

    private ProvisioningPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = new ProvisioningPipeline(new ProvisioningStepRegistry);
    }

    public function test_build_plan_returns_eighteen_ordered_steps(): void
    {
        $run = $this->makePendingRun();
        $plan = $this->pipeline->buildPlan($run);

        $this->assertSame($run->id, $plan->provisioningRunId);
        $this->assertSame(18, $plan->stepCount());
        $this->assertSame('validate', $plan->orderedStepKeys[0]);
        $this->assertSame('mark_ready', $plan->orderedStepKeys[17]);
    }

    public function test_steps_blocked_after_build_failure_include_downstream_mark_steps(): void
    {
        $blocked = $this->pipeline->stepsBlockedAfterFailure('build');

        $this->assertContains('migrate', $blocked);
        $this->assertContains('mark_deployed', $blocked);
        $this->assertContains('mark_ready', $blocked);
        $this->assertNotContains('build', $blocked);
        $this->assertNotContains('validate', $blocked);
    }

    public function test_execute_method_is_explicitly_disabled(): void
    {
        $run = $this->makePendingRun();

        $this->expectException(BadMethodCallException::class);
        $this->pipeline->execute($run);
    }

    public function test_terminal_run_cannot_be_replanned_on_same_record(): void
    {
        $run = $this->makePendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_FAILED);

        $this->expectException(InvalidArgumentException::class);
        $this->pipeline->buildPlan($run->fresh());
    }

    public function test_retry_contract_requires_new_run_and_terminal_source(): void
    {
        $runA = $this->makePendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($runA, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($runA->fresh(), ProvisioningRun::STATUS_FAILED);

        $runB = ProvisioningRun::query()->create([
            'installation_id' => $runA->installation_id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'retry',
            'retry_of_run_id' => $runA->id,
        ]);

        $this->pipeline->assertRetryUsesNewRun($runA->fresh(), $runB);

        $this->expectException(InvalidArgumentException::class);
        $this->pipeline->assertRetryUsesNewRun($runA->fresh(), $runB->fresh()->fill(['retry_of_run_id' => null]));
    }

    private function makePendingRun(): ProvisioningRun
    {
        $client = Client::query()->create([
            'company_name' => 'Pipeline Contract Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Pipeline Installation',
            'subdomain' => 'pipe-'.uniqid(),
            'status' => 'active',
        ]);

        return ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);
    }
}
