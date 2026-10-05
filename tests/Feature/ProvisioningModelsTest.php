<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PDO;
use ReflectionClass;
use Tests\TestCase;

class ProvisioningModelsTest extends TestCase
{
    private const REQUIRED_DATABASE = 'mkdpro_control_provisioning_test';

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql requis pour ProvisioningModelsTest.');
        }

        try {
            $this->bootProvisioningTestDatabase();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Base provisioning_test indisponible : '.$e->getMessage());
        }
    }

    protected function connection(): string
    {
        return 'provisioning_test';
    }

    private function bootProvisioningTestDatabase(): void
    {
        $database = config('database.connections.provisioning_test.database');
        $this->assertSame(self::REQUIRED_DATABASE, $database);

        $config = config('database.connections.provisioning_test');
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS `'.self::REQUIRED_DATABASE.'` '
            .'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        if (! self::$schemaReady) {
            Artisan::call('migrate', [
                '--database' => $this->connection(),
                '--force' => true,
            ]);
            self::$schemaReady = true;
        }
    }

    public function test_provisioning_run_model_exists_and_uses_correct_table(): void
    {
        $run = new ProvisioningRun;
        $this->assertSame('provisioning_runs', $run->getTable());
    }

    public function test_provisioning_run_step_model_exists_and_uses_correct_table(): void
    {
        $step = new ProvisioningRunStep;
        $this->assertSame('provisioning_run_steps', $step->getTable());
    }

    public function test_provisioning_run_fillable_excludes_generated_and_timestamps(): void
    {
        $fillable = (new ProvisioningRun)->getFillable();

        $this->assertContains('installation_id', $fillable);
        $this->assertContains('metadata', $fillable);
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('active_installation_key', $fillable);
        $this->assertNotContains('created_at', $fillable);
        $this->assertNotContains('updated_at', $fillable);
    }

    public function test_provisioning_run_casts_metadata_and_datetimes(): void
    {
        $casts = (new ProvisioningRun)->getCasts();

        $this->assertSame('array', $casts['metadata']);
        $this->assertSame('datetime', $casts['started_at']);
        $this->assertSame('datetime', $casts['finished_at']);
    }

    public function test_provisioning_run_step_fillable_and_casts(): void
    {
        $fillable = (new ProvisioningRunStep)->getFillable();

        $this->assertContains('step_key', $fillable);
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('created_at', $fillable);

        $casts = (new ProvisioningRunStep)->getCasts();
        $this->assertSame('array', $casts['input_summary']);
        $this->assertSame('array', $casts['output_summary']);
        $this->assertSame('array', $casts['metadata']);
        $this->assertSame('datetime', $casts['started_at']);
        $this->assertSame('datetime', $casts['finished_at']);
    }

    public function test_run_status_constants_are_declarative(): void
    {
        $this->assertSame('pending', ProvisioningRun::STATUS_PENDING);
        $this->assertSame('running', ProvisioningRun::STATUS_RUNNING);
        $this->assertSame('succeeded', ProvisioningRun::STATUS_SUCCEEDED);
        $this->assertSame('failed', ProvisioningRun::STATUS_FAILED);
        $this->assertSame('cancelled', ProvisioningRun::STATUS_CANCELLED);
        $this->assertSame('manual_intervention_required', ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED);
    }

    public function test_step_status_and_eighteen_step_key_constants(): void
    {
        $this->assertSame('skipped', ProvisioningRunStep::STATUS_SKIPPED);
        $this->assertCount(18, ProvisioningRunStep::CANONICAL_STEP_KEYS);
        $this->assertSame('validate', ProvisioningRunStep::STEP_VALIDATE);
        $this->assertSame('mark_ready', ProvisioningRunStep::STEP_MARK_READY);
    }

    public function test_no_provisioning_engine_classes_or_routes(): void
    {
        $this->assertFalse(class_exists(\App\Services\ProvisioningService::class));
        $this->assertFalse(class_exists(\App\Jobs\ProvisioningJob::class));
        $this->assertFalse(class_exists(\App\Http\Controllers\ProvisioningController::class));

        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning'),
        );
        $this->assertCount(0, $routes);
        }

    public function test_json_casts_on_dedicated_database(): void
    {
        $installation = $this->createSyntheticInstallation('json-'.uniqid());

        $run = ProvisioningRun::on($this->connection())->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_SUCCEEDED,
            'trigger' => 'manual',
            'metadata' => ['duration_ms' => 1200, 'result' => 'ok'],
            'finished_at' => now(),
        ]);

        $run->refresh();
        $this->assertIsArray($run->metadata);
        $this->assertSame(1200, $run->metadata['duration_ms']);

        $step = ProvisioningRunStep::on($this->connection())->create([
            'provisioning_run_id' => $run->id,
            'step_key' => ProvisioningRunStep::STEP_VALIDATE,
            'step_order' => 1,
            'status' => ProvisioningRunStep::STATUS_SUCCEEDED,
            'input_summary' => ['target' => 'test'],
            'output_summary' => ['result' => 'ok'],
            'metadata' => ['redacted' => true],
        ]);

        $step->refresh();
        $this->assertSame('test', $step->input_summary['target']);
        $this->assertTrue($step->metadata['redacted']);
    }

    public function test_active_installation_key_is_read_only_and_generated(): void
    {
        $installation = $this->createSyntheticInstallation('gen-'.uniqid());

        $runA = ProvisioningRun::on($this->connection())->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $runA->refresh();
        $this->assertSame($installation->id, (int) $runA->active_installation_key);

        $runA->update([
            'status' => ProvisioningRun::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        $runA->refresh();
        $this->assertNull($runA->active_installation_key);

        $runB = ProvisioningRun::on($this->connection())->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $runB->refresh();
        $this->assertSame($installation->id, (int) $runB->active_installation_key);
    }

    public function test_relationships_on_synthetic_runs_and_steps(): void
    {
        $installation = $this->createSyntheticInstallation('rel-'.uniqid());
        $user = User::on($this->connection())->create([
            'name' => 'Provisioner Test',
            'email' => 'prov-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
        ]);

        $runA = ProvisioningRun::on($this->connection())->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_SUCCEEDED,
            'trigger' => 'manual',
            'requested_by' => $user->id,
            'finished_at' => now(),
        ]);

        $runB = ProvisioningRun::on($this->connection())->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_FAILED,
            'trigger' => 'retry',
            'retry_of_run_id' => $runA->id,
            'finished_at' => now(),
        ]);

        ProvisioningRunStep::on($this->connection())->create([
            'provisioning_run_id' => $runB->id,
            'step_key' => ProvisioningRunStep::STEP_VALIDATE,
            'step_order' => 1,
            'status' => ProvisioningRunStep::STATUS_SUCCEEDED,
        ]);

        ProvisioningRunStep::on($this->connection())->create([
            'provisioning_run_id' => $runB->id,
            'step_key' => ProvisioningRunStep::STEP_DEPLOY,
            'step_order' => 6,
            'status' => ProvisioningRunStep::STATUS_FAILED,
            'error_code' => 'deploy_failed',
        ]);

        $installation = Installation::on($this->connection())->findOrFail($installation->id);
        $this->assertGreaterThanOrEqual(2, $installation->provisioningRuns()->count());

        $runB = ProvisioningRun::on($this->connection())
            ->with(['installation', 'retryOf', 'steps'])
            ->findOrFail($runB->id);

        $this->assertSame($installation->id, $runB->installation->id);
        $this->assertSame($runA->id, $runB->retryOf->id);
        $this->assertCount(2, $runB->steps);

        $runA = ProvisioningRun::on($this->connection())
            ->with(['retries', 'requestedBy'])
            ->findOrFail($runA->id);
        $this->assertSame($user->id, $runA->requestedBy->id);
        $this->assertCount(1, $runA->retries);
        $this->assertSame($runB->id, $runA->retries->first()->id);

        $step = ProvisioningRunStep::on($this->connection())
            ->with('provisioningRun')
            ->where('provisioning_run_id', $runB->id)
            ->where('step_key', ProvisioningRunStep::STEP_DEPLOY)
            ->firstOrFail();
        $this->assertSame($runB->id, $step->provisioningRun->id);
    }

    public function test_models_do_not_define_provisioning_business_methods(): void
    {
        foreach ([ProvisioningRun::class, ProvisioningRunStep::class] as $class) {
            $methods = array_map(
                fn (\ReflectionMethod $m) => $m->getName(),
                (new ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            );

            foreach (['start', 'fail', 'retry', 'complete', 'run', 'provision'] as $forbidden) {
                $this->assertNotContains($forbidden, $methods, $class.'::'.$forbidden);
            }
        }
    }

    public function test_mkdpro_control_default_connection_has_no_provisioning_tables_when_mysql(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'mkdpro_control') {
            $this->markTestSkipped('Contrôle mkdpro_control mysql non applicable.');
        }

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('Connexion mysql dev indisponible.');
        }

        $this->assertFalse(Schema::connection('mysql')->hasTable('provisioning_runs'));
    }

    private function createSyntheticInstallation(string $subdomain): Installation
    {
        $client = Client::on($this->connection())->create([
            'company_name' => 'Synthetic Provisioning Client',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        return Installation::on($this->connection())->create([
            'client_id' => $client->id,
            'name' => 'Synthetic Installation',
            'subdomain' => $subdomain,
            'status' => 'active',
        ]);
    }
}
