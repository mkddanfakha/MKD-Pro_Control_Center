<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\Exceptions\InvalidProvisioningRunTransition;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningExecutionAuditService;
use App\Services\Provisioning\ProvisioningPersistedRunOrchestrator;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\ProvisioningRunStateService;
use App\Services\ProvisioningRunStepStateService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesProvisioningTestDatabase;
use Tests\Fakes\Provisioning\FakeFailedStep;
use Tests\Fakes\Provisioning\FakeManualInterventionStep;
use Tests\Fakes\Provisioning\FakeSkippedStep;
use Tests\Fakes\Provisioning\FakeSpyStep;
use Tests\Fakes\Provisioning\FakeSuccessfulStep;
use Tests\TestCase;

class ProvisioningRunOrchestrationTest extends TestCase
{
    use UsesProvisioningTestDatabase;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProvisioningTestDatabase();
        $this->connection = $this->provisioningTestConnection();
        $this->assertProvisioningTablesExist();
        FakeSuccessfulStep::reset();
        FakeSpyStep::reset();
    }

    public function test_pending_run_with_all_successful_steps_ends_succeeded(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeSuccessfulStep('prepare', 10),
            new FakeSuccessfulStep('finalize', 20),
        ]);

        $run = $this->createPendingRun();
        $result = $pipeline->runPersisted($run);

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_SUCCEEDED, $result->outcome);

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $steps = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->orderBy('step_order')
            ->get();

        $this->assertCount(2, $steps);
        $this->assertTrue($steps->every(fn ($s) => $s->status === ProvisioningRunStep::STATUS_SUCCEEDED));
    }

    public function test_skipped_step_allows_run_to_succeed_and_executes_following_steps(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeSuccessfulStep('a', 1),
            new FakeSkippedStep('b', 2),
            new FakeSpyStep('c', 3),
        ]);

        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_SUCCEEDED, $run->status);

        $statusByKey = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->pluck('status', 'step_key');

        $this->assertSame(ProvisioningRunStep::STATUS_SUCCEEDED, $statusByKey['a']);
        $this->assertSame(ProvisioningRunStep::STATUS_SKIPPED, $statusByKey['b']);
        $this->assertSame(ProvisioningRunStep::STATUS_SUCCEEDED, $statusByKey['c']);
        $this->assertSame(['c'], FakeSpyStep::$executionOrder);
    }

    public function test_failed_step_stops_pipeline_and_leaves_following_steps_pending(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeSuccessfulStep('a', 1),
            new FakeFailedStep('b', 2),
            new FakeSpyStep('c', 3),
        ]);

        $run = $this->createPendingRun();
        $result = $pipeline->runPersisted($run);

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_FAILED, $result->outcome);

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_FAILED, $run->status);
        $this->assertSame('fake_failure', $run->error_code);

        $statusByKey = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->pluck('status', 'step_key');

        $this->assertSame(ProvisioningRunStep::STATUS_SUCCEEDED, $statusByKey['a']);
        $this->assertSame(ProvisioningRunStep::STATUS_FAILED, $statusByKey['b']);
        $this->assertSame(ProvisioningRunStep::STATUS_PENDING, $statusByKey['c']);
        $this->assertSame([], FakeSpyStep::$executionOrder);
    }

    public function test_manual_intervention_stops_pipeline(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeManualInterventionStep('manual', 1),
            new FakeSpyStep('never', 2),
        ]);

        $run = $this->createPendingRun();
        $result = $pipeline->runPersisted($run);

        $this->assertSame(ProvisioningPipelineResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED, $result->outcome);

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->status);
        $this->assertSame([], FakeSpyStep::$executionOrder);
    }

    public function test_succeeded_terminal_run_cannot_be_reexecuted(): void
    {
        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);
        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_failed_terminal_run_cannot_be_reexecuted(): void
    {
        $pipeline = $this->pipelineWithSteps([new FakeFailedStep('fail', 1)]);
        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_cancelled_terminal_run_cannot_be_reexecuted(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_CANCELLED);

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_no_duplicate_provisioning_run_steps_for_same_key(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeSuccessfulStep('prepare', 10),
            new FakeSuccessfulStep('finalize', 20),
        ]);

        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $count = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->count();

        $distinct = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->distinct('step_key')
            ->count('step_key');

        $this->assertSame(2, $count);
        $this->assertSame(2, $distinct);
    }

    public function test_persisted_step_order_matches_registry_order(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeSpyStep('third', 30),
            new FakeSpyStep('first', 10),
            new FakeSpyStep('second', 20),
        ]);

        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $orders = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->orderBy('step_order')
            ->pluck('step_key')
            ->all();

        $this->assertSame(['first', 'second', 'third'], $orders);
        $this->assertSame(['first', 'second', 'third'], FakeSpyStep::$executionOrder);
    }

    public function test_same_context_run_id_is_passed_to_executable_steps(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeSuccessfulStep('a', 1),
            new FakeSuccessfulStep('b', 2),
        ]);

        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $this->assertSame([$run->id, $run->id], FakeSuccessfulStep::$receivedRunIds);
    }

    public function test_step_timestamps_are_set_on_terminal_outcomes(): void
    {
        $this->travelTo('2026-10-15 14:00:00');

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('done', 1)]);
        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $step = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->sole();

        $this->assertSame('2026-10-15 14:00:00', $step->started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 14:00:00', $step->finished_at?->format('Y-m-d H:i:s'));
    }

    public function test_metadata_and_messages_contain_no_forbidden_secret_keys(): void
    {
        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('done', 1)]);
        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $step = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->sole();

        $this->assertIsArray($step->metadata);
        foreach (array_keys($step->metadata ?? []) as $key) {
            $this->assertFalse(ProvisioningContext::isForbiddenSecretKey($key));
        }

        if ($step->error_message !== null) {
            $this->assertStringNotContainsString('password=', $step->error_message);
        }
    }

    public function test_running_run_is_refused_for_persisted_orchestration(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    /**
     * @param  list<\App\Contracts\Provisioning\ProvisioningStep>  $steps
     */
    private function pipelineWithSteps(array $steps): ProvisioningPipeline
    {
        $registry = new ProvisioningStepRegistry;

        foreach ($steps as $step) {
            $registry->register($step);
        }

        $orchestrator = new ProvisioningPersistedRunOrchestrator(
            $registry,
            app(ProvisioningRunStateService::class),
            app(ProvisioningRunStepStateService::class),
            app(ProvisioningExecutionAuditService::class),
        );

        return new ProvisioningPipeline($registry, $orchestrator);
    }

    private function createPendingRun(): ProvisioningRun
    {
        $now = now();

        $clientId = DB::connection($this->connection)->table('clients')->insertGetId([
            'company_name' => 'Orchestration Co',
            'contact_name' => 'Test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $installationId = DB::connection($this->connection)->table('installations')->insertGetId([
            'client_id' => $clientId,
            'name' => 'Orchestration Installation',
            'subdomain' => 'orch-'.uniqid(),
            'domain' => 'client.example.test',
            'database_name' => 'mkd_orch_test',
            'database_host' => 'mysql.internal.test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProvisioningRun::on($this->connection)->create([
            'installation_id' => $installationId,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);
    }
}
