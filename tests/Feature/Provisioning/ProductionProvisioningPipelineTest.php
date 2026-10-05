<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Tests\Concerns\UsesProvisioningTestDatabase;
use Tests\TestCase;

class ProductionProvisioningPipelineTest extends TestCase
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

    public function test_pure_pipeline_stops_on_first_non_implemented_infrastructure_step(): void
    {
        $registry = app(ProvisioningStepRegistry::class);
        $pipeline = new ProvisioningPipeline($registry);

        $run = $this->createPendingRunWithValidInstallation();
        $context = \App\DTO\Provisioning\ProvisioningContext::fromRun($run);

        $result = $pipeline->run($context);

        $this->assertSame(
            ProvisioningPipelineResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
        $this->assertSame(ProvisioningRunStep::STEP_RESERVE, $result->stoppedAtStepKey);
    }

    public function test_run_persisted_with_production_steps_does_not_fake_installation_success(): void
    {
        $pipeline = app(ProvisioningPipeline::class);
        $run = $this->createPendingRunWithValidInstallation();

        $result = $pipeline->runPersisted($run);

        $this->assertSame(
            ProvisioningPipelineResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, $run->status);
        $this->assertNotSame(ProvisioningRun::STATUS_SUCCEEDED, $run->status);

        $validate = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_VALIDATE)
            ->sole();

        $reserve = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->where('step_key', ProvisioningRunStep::STEP_RESERVE)
            ->sole();

        $this->assertSame(ProvisioningRunStep::STATUS_SUCCEEDED, $validate->status);
        $this->assertSame(
            ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
            $reserve->status,
        );

        $pendingAfter = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->where('status', ProvisioningRunStep::STATUS_PENDING)
            ->count();

        $this->assertGreaterThan(0, $pendingAfter);
    }

    public function test_production_steps_execute_in_canonical_order_in_pure_pipeline(): void
    {
        $registry = app(ProvisioningStepRegistry::class);
        $keys = array_map(
            fn ($step) => $step->stepKey(),
            $registry->orderedExecutableSteps(),
        );

        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $keys);
    }

    private function createPendingRunWithValidInstallation(): ProvisioningRun
    {
        $now = now();

        $clientId = \Illuminate\Support\Facades\DB::connection($this->connection)->table('clients')->insertGetId([
            'company_name' => 'Production Pipeline Co',
            'contact_name' => 'Test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $installationId = \Illuminate\Support\Facades\DB::connection($this->connection)->table('installations')->insertGetId([
            'client_id' => $clientId,
            'name' => 'Pipeline Installation',
            'subdomain' => 'pipe-'.uniqid(),
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
