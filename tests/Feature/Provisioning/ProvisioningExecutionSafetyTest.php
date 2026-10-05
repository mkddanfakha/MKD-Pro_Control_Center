<?php

namespace Tests\Feature\Provisioning;

use App\Exceptions\InvalidProvisioningRunTransition;
use App\Exceptions\Provisioning\ProvisioningExecutableRegistryEmptyException;
use App\Exceptions\Provisioning\ProvisioningStepExecutionException;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningExecutionAuditService;
use App\Services\Provisioning\ProvisioningPersistedRunOrchestrator;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\ProvisioningRunStateService;
use App\Services\ProvisioningRunStepStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\UsesProvisioningTestDatabase;
use Tests\Fakes\Provisioning\FakeSuccessfulStep;
use Tests\Fakes\Provisioning\FakeThrowingStep;
use Tests\TestCase;

class ProvisioningExecutionSafetyTest extends TestCase
{
    use UsesProvisioningTestDatabase;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProvisioningTestDatabase();
        $this->connection = $this->provisioningTestConnection();
        $this->assertProvisioningTablesExist();
    }

    public function test_pending_run_can_start_when_executable_registry_is_populated(): void
    {
        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('done', 1)]);
        $run = $this->createPendingRun();

        $result = $pipeline->runPersisted($run);

        $this->assertSame('succeeded', $result->outcome);
        $this->assertSame(ProvisioningRun::STATUS_SUCCEEDED, $run->fresh()->status);
    }

    public function test_running_run_is_refused_for_persisted_orchestration(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_succeeded_terminal_run_is_refused(): void
    {
        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);
        $run = $this->createPendingRun();
        $pipeline->runPersisted($run);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_failed_terminal_run_is_refused(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_FAILED);

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_manual_intervention_terminal_run_is_refused(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo(
            $run->fresh(),
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        );

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_cancelled_terminal_run_is_refused(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_CANCELLED);

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_second_start_attempt_is_rejected_when_run_is_already_running(): void
    {
        $run = $this->createPendingRun();
        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $pipeline = $this->pipelineWithSteps([new FakeSuccessfulStep('only', 1)]);

        $this->expectException(InvalidProvisioningRunTransition::class);
        $pipeline->runPersisted($run->fresh());
    }

    public function test_claim_uses_row_level_lock_before_pending_to_running(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningPersistedRunOrchestrator.php'));

        $this->assertStringContainsString('lockForUpdate', $source);
        $this->assertStringContainsString('claimPendingRunForExecution', $source);
    }

    public function test_uncaught_step_exception_does_not_leave_run_running(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeThrowingStep('boom', 1, new RuntimeException('Erreur simulée step.')),
        ]);

        $run = $this->createPendingRun();

        try {
            $pipeline->runPersisted($run);
            $this->fail('ProvisioningStepExecutionException attendue.');
        } catch (ProvisioningStepExecutionException $exception) {
            $this->assertSame('boom', $exception->stepKey);
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotSame(ProvisioningRun::STATUS_RUNNING, $run->status);
    }

    public function test_uncaught_exception_produces_coherent_terminal_run_state(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeThrowingStep('boom', 1, new RuntimeException('Diagnostic visible.')),
        ]);

        $run = $this->createPendingRun();

        try {
            $pipeline->runPersisted($run);
        } catch (ProvisioningStepExecutionException) {
        }

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('boom', (string) $run->error_message);
        $this->assertStringStartsWith('uncaught_', (string) $run->error_code);
    }

    public function test_uncaught_exception_marks_step_as_failed(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeThrowingStep('boom', 1, new RuntimeException('Step failure.')),
        ]);

        $run = $this->createPendingRun();

        try {
            $pipeline->runPersisted($run);
        } catch (ProvisioningStepExecutionException) {
        }

        $step = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', 'boom')
            ->sole();

        $this->assertSame(ProvisioningRunStep::STATUS_FAILED, $step->status);
        $this->assertNotNull($step->finished_at);
    }

    public function test_empty_executable_registry_prevents_false_success(): void
    {
        $run = $this->createPendingRun();

        $orchestrator = new ProvisioningPersistedRunOrchestrator(
            new ProvisioningStepRegistry,
            app(ProvisioningRunStateService::class),
            app(ProvisioningRunStepStateService::class),
            app(ProvisioningExecutionAuditService::class),
        );

        $this->expectException(ProvisioningExecutableRegistryEmptyException::class);

        try {
            $orchestrator->runPersisted($run);
        } finally {
            $run->refresh();
            $this->assertSame(ProvisioningRun::STATUS_PENDING, $run->status);
            $this->assertNull($run->started_at);
        }
    }

    public function test_error_diagnostics_do_not_persist_secrets(): void
    {
        $pipeline = $this->pipelineWithSteps([
            new FakeThrowingStep(
                'secret-step',
                1,
                new RuntimeException('api_key=super-secret-token password=abc'),
            ),
        ]);

        $run = $this->createPendingRun();

        try {
            $pipeline->runPersisted($run);
        } catch (ProvisioningStepExecutionException) {
        }

        $run->refresh();
        $step = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->sole();

        $this->assertStringNotContainsString('super-secret-token', (string) $step->error_message);
        $this->assertStringNotContainsString('password=abc', (string) $step->error_message);
        $this->assertStringNotContainsString('super-secret-token', (string) $run->error_message);
    }

    public function test_retry_remains_a_new_provisioning_run_via_factory(): void
    {
        $installation = $this->createInstallationRecord();
        $factory = app(ProvisioningRunFactory::class);

        $first = $factory->createRequest($installation);
        app(ProvisioningRunStateService::class)->transitionTo($first, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($first->fresh(), ProvisioningRun::STATUS_FAILED);

        $retry = $factory->createRequest($installation->fresh());

        $this->assertNotSame($first->id, $retry->id);
        $this->assertSame($first->id, $retry->retry_of_run_id);
        $this->assertSame(ProvisioningRun::STATUS_PENDING, $retry->status);
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
        $installation = $this->createInstallationRecord();

        return ProvisioningRun::on($this->connection)->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);
    }

    private function createInstallationRecord(): \App\Models\Installation
    {
        $now = now();

        $clientId = DB::connection($this->connection)->table('clients')->insertGetId([
            'company_name' => 'Safety Co',
            'contact_name' => 'Test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $installationId = DB::connection($this->connection)->table('installations')->insertGetId([
            'client_id' => $clientId,
            'name' => 'Safety Installation',
            'subdomain' => 'safe-'.uniqid(),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return \App\Models\Installation::on($this->connection)->findOrFail($installationId);
    }
}
