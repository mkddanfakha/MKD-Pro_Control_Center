<?php

namespace Tests\Unit\Services\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Provisioning\FakeFailedStep;
use Tests\Fakes\Provisioning\FakeManualInterventionStep;
use Tests\Fakes\Provisioning\FakeSkippedStep;
use Tests\Fakes\Provisioning\FakeSpyStep;
use Tests\Fakes\Provisioning\FakeSuccessfulStep;
use Tests\TestCase;

class ProvisioningPipelineOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeSuccessfulStep::reset();
        FakeSpyStep::reset();
    }

    public function test_all_successful_steps_yield_pipeline_succeeded(): void
    {
        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeSuccessfulStep('prepare', 10));
        $registry->register(new FakeSuccessfulStep('finalize', 20));

        $pipeline = new ProvisioningPipeline($registry);
        $context = $this->makeContext();

        $result = $pipeline->run($context);

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(2, $result->stepResults);
        $this->assertNull($result->stoppedAtStepKey);
    }

    public function test_skipped_step_allows_pipeline_to_continue(): void
    {
        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeSuccessfulStep('a', 1));
        $registry->register(new FakeSkippedStep('b', 2));
        $registry->register(new FakeSuccessfulStep('c', 3));

        $result = (new ProvisioningPipeline($registry))->run($this->makeContext());

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(3, $result->stepResults);
        $this->assertSame(ProvisioningStepResult::OUTCOME_SKIPPED, $result->stepResults[1]->outcome);
    }

    public function test_failed_step_stops_pipeline_immediately(): void
    {
        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeSuccessfulStep('a', 1));
        $registry->register(new FakeFailedStep('b', 2));
        $registry->register(new FakeSpyStep('c', 3));

        $result = (new ProvisioningPipeline($registry))->run($this->makeContext());

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_FAILED, $result->outcome);
        $this->assertSame('b', $result->stoppedAtStepKey);
        $this->assertCount(2, $result->stepResults);
        $this->assertSame([], FakeSpyStep::$executionOrder);
    }

    public function test_manual_intervention_stops_pipeline_immediately(): void
    {
        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeManualInterventionStep('manual', 1));
        $registry->register(new FakeSpyStep('never', 2));

        $result = (new ProvisioningPipeline($registry))->run($this->makeContext());

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED, $result->outcome);
        $this->assertSame('manual', $result->stoppedAtStepKey);
        $this->assertSame([], FakeSpyStep::$executionOrder);
    }

    public function test_steps_execute_in_deterministic_order(): void
    {
        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeSpyStep('third', 30));
        $registry->register(new FakeSpyStep('first', 10));
        $registry->register(new FakeSpyStep('second', 20));

        (new ProvisioningPipeline($registry))->run($this->makeContext());

        $this->assertSame(['first', 'second', 'third'], FakeSpyStep::$executionOrder);
    }

    public function test_same_context_instance_is_passed_to_each_step(): void
    {
        $registry = new ProvisioningStepRegistry;
        $registry->register(new FakeSuccessfulStep('a', 1));
        $registry->register(new FakeSuccessfulStep('b', 2));

        $context = $this->makeContext();
        (new ProvisioningPipeline($registry))->run($context);

        $this->assertSame([$context->provisioningRunId, $context->provisioningRunId], FakeSuccessfulStep::$receivedRunIds);
    }

    public function test_empty_registry_run_succeeds_with_no_step_results(): void
    {
        $registry = new ProvisioningStepRegistry;
        $this->assertTrue($registry->isEmpty());

        $result = (new ProvisioningPipeline($registry))->run($this->makeContext());

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame([], $result->stepResults);
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Orchestration Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Orchestration Installation',
            'subdomain' => 'orch-'.uniqid(),
            'domain' => 'example.test',
            'database_name' => 'mkd_orch_test',
            'database_host' => 'db.internal.test',
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        return ProvisioningContext::fromRun($run);
    }
}
