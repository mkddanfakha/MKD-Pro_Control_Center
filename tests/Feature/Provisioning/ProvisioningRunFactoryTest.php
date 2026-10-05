<?php

namespace Tests\Feature\Provisioning;

use App\Exceptions\Provisioning\ProvisioningRunCreationException;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\ProvisioningRunStateService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\UsesProvisioningTestDatabase;
use Tests\TestCase;

class ProvisioningRunFactoryTest extends TestCase
{
    use UsesProvisioningTestDatabase;

    private string $connection;

    private ProvisioningRunFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProvisioningTestDatabase();
        $this->connection = $this->provisioningTestConnection();
        $this->assertProvisioningTablesExist();
        $this->factory = new ProvisioningRunFactory(new ProvisioningStepRegistry);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_valid_installation_creates_pending_run(): void
    {
        $installation = $this->createInstallation();

        $run = $this->factory->createRequest($installation);

        $this->assertSame(ProvisioningRun::STATUS_PENDING, $run->status);
        $this->assertSame($installation->id, $run->installation_id);
        $this->assertNull($run->retry_of_run_id);
        $this->assertSame('manual', $run->trigger);
        $this->assertNull($run->started_at);
        $this->assertNull($run->finished_at);
    }

    public function test_valid_installation_creates_all_canonical_steps_pending(): void
    {
        $installation = $this->createInstallation();
        $run = $this->factory->createRequest($installation);

        $steps = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->orderBy('step_order')
            ->get();

        $this->assertCount(18, $steps);
        $this->assertTrue($steps->every(fn ($s) => $s->status === ProvisioningRunStep::STATUS_PENDING));
        $this->assertNull($steps->first()->started_at);
    }

    public function test_no_step_is_executed_during_creation(): void
    {
        $run = $this->factory->createRequest($this->createInstallation());

        $this->assertSame(
            0,
            ProvisioningRunStep::on($this->connection)
                ->where('provisioning_run_id', $run->id)
                ->where('status', '!=', ProvisioningRunStep::STATUS_PENDING)
                ->count(),
        );
    }

    public function test_missing_installation_record_is_rejected(): void
    {
        $installation = Installation::on($this->connection)->make([
            'client_id' => 1,
            'name' => 'Ghost',
            'subdomain' => 'ghost',
            'status' => 'active',
        ]);

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation);
    }

    public function test_terminated_installation_is_rejected(): void
    {
        $installation = $this->createInstallation([
            'status' => 'terminated',
            'terminated_at' => now(),
        ]);

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation);
    }

    public function test_missing_client_is_rejected(): void
    {
        $installation = $this->createInstallationWithMissingClientReference();

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation);
    }

    public function test_empty_subdomain_is_rejected(): void
    {
        $installation = $this->createInstallation();
        $installation->subdomain = '   ';

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation);
    }

    public function test_missing_database_name_and_host_still_allowed(): void
    {
        $installation = $this->createInstallation([
            'database_name' => null,
            'database_host' => null,
        ]);

        $run = $this->factory->createRequest($installation);

        $this->assertSame(ProvisioningRun::STATUS_PENDING, $run->status);
        $this->assertNull($run->metadata['database_name'] ?? null);
    }

    public function test_existing_pending_run_blocks_new_request(): void
    {
        $installation = $this->createInstallation();
        $this->factory->createRequest($installation);

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation->fresh());
    }

    public function test_existing_running_run_blocks_new_request(): void
    {
        $installation = $this->createInstallation();
        $run = $this->factory->createRequest($installation);

        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation->fresh());
    }

    public function test_existing_succeeded_run_blocks_new_request(): void
    {
        $installation = $this->createInstallation();
        $run = $this->factory->createRequest($installation);

        app(ProvisioningRunStateService::class)->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($run->fresh(), ProvisioningRun::STATUS_SUCCEEDED);

        $this->expectException(ProvisioningRunCreationException::class);
        $this->factory->createRequest($installation->fresh());
    }

    public function test_failed_run_allows_retry_run_with_retry_of_run_id(): void
    {
        $installation = $this->createInstallation();
        $failed = $this->factory->createRequest($installation);

        app(ProvisioningRunStateService::class)->transitionTo($failed, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo($failed->fresh(), ProvisioningRun::STATUS_FAILED);

        $retry = $this->factory->createRequest($installation->fresh());

        $this->assertSame(ProvisioningRun::STATUS_PENDING, $retry->status);
        $this->assertSame($failed->id, $retry->retry_of_run_id);
        $this->assertSame('retry', $retry->trigger);
    }

    public function test_manual_intervention_run_allows_retry(): void
    {
        $installation = $this->createInstallation();
        $prior = $this->factory->createRequest($installation);

        app(ProvisioningRunStateService::class)->transitionTo($prior, ProvisioningRun::STATUS_RUNNING);
        app(ProvisioningRunStateService::class)->transitionTo(
            $prior->fresh(),
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        );

        $retry = $this->factory->createRequest($installation->fresh());

        $this->assertSame($prior->id, $retry->retry_of_run_id);
    }

    public function test_cancelled_run_allows_retry(): void
    {
        $installation = $this->createInstallation();
        $prior = $this->factory->createRequest($installation);

        app(ProvisioningRunStateService::class)->transitionTo($prior, ProvisioningRun::STATUS_CANCELLED);

        $retry = $this->factory->createRequest($installation->fresh());

        $this->assertSame($prior->id, $retry->retry_of_run_id);
    }

    public function test_metadata_contains_no_forbidden_secret_keys(): void
    {
        $run = $this->factory->createRequest($this->createInstallation());

        foreach (array_keys($run->metadata ?? []) as $key) {
            $this->assertFalse(\App\DTO\Provisioning\ProvisioningContext::isForbiddenSecretKey($key));
        }
    }

    public function test_step_order_matches_registry(): void
    {
        $registry = new ProvisioningStepRegistry;
        $factory = new ProvisioningRunFactory($registry);

        $run = $factory->createRequest($this->createInstallation());

        $persisted = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->orderBy('step_order')
            ->pluck('step_key')
            ->all();

        $expected = array_column($registry->orderedDescriptors(), 'step_key');

        $this->assertSame($expected, $persisted);
    }

    public function test_step_sync_failure_rolls_back_run_creation(): void
    {
        $registry = Mockery::mock(ProvisioningStepRegistry::class);
        $registry->shouldReceive('orderedDescriptors')->andThrow(new \RuntimeException('sync failure'));

        $factory = new ProvisioningRunFactory($registry);
        $installation = $this->createInstallation();

        try {
            $factory->createRequest($installation);
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            $this->assertSame(
                0,
                ProvisioningRun::on($this->connection)->where('installation_id', $installation->id)->count(),
            );
        }
    }

    public function test_second_call_after_pending_creation_is_refused(): void
    {
        $installation = $this->createInstallation();
        $this->factory->createRequest($installation);

        $this->expectException(ProvisioningRunCreationException::class);
        $this->expectExceptionMessage('déjà en cours');
        $this->factory->createRequest($installation->fresh());
    }

    public function test_no_duplicate_step_keys_for_same_run(): void
    {
        $run = $this->factory->createRequest($this->createInstallation());

        $count = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->count();

        $distinct = ProvisioningRunStep::on($this->connection)
            ->where('provisioning_run_id', $run->id)
            ->distinct('step_key')
            ->count('step_key');

        $this->assertSame(18, $count);
        $this->assertSame(18, $distinct);
    }

    public function test_factory_source_never_invokes_pipeline_execution(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningRunFactory.php'));

        $this->assertStringNotContainsString('runPersisted', $source);
        $this->assertStringNotContainsString('ProvisioningPipeline', $source);
        $this->assertStringNotContainsString('ProvisioningPersistedRunOrchestrator', $source);
        $this->assertStringNotContainsString('ProvisioningRunStepStateService', $source);
    }

    public function test_pipeline_is_not_resolved_during_factory_creation(): void
    {
        $pipeline = Mockery::mock(ProvisioningPipeline::class);
        $pipeline->shouldNotReceive('runPersisted');
        $this->app->instance(ProvisioningPipeline::class, $pipeline);

        $this->factory->createRequest($this->createInstallation());
    }

    private function createInstallationWithMissingClientReference(): Installation
    {
        $now = now();
        $subdomain = 'orphan-'.uniqid();

        DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=0');

        $installationId = DB::connection($this->connection)->table('installations')->insertGetId([
            'client_id' => 999999,
            'name' => 'Orphan Installation',
            'subdomain' => $subdomain,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=1');

        return Installation::on($this->connection)->findOrFail($installationId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInstallation(array $overrides = []): Installation
    {
        $now = now();

        $clientId = DB::connection($this->connection)->table('clients')->insertGetId([
            'company_name' => 'Factory Co',
            'contact_name' => 'Test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Installation::on($this->connection)->create(array_merge([
            'client_id' => $clientId,
            'name' => 'Factory Installation',
            'subdomain' => 'fact-'.uniqid(),
            'status' => 'active',
        ], $overrides));
    }
}
