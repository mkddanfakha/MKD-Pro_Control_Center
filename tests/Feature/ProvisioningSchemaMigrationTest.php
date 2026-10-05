<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Validation du schéma provisioning sur la base MySQL dédiée mkdpro_control_provisioning_test uniquement.
 */
class ProvisioningSchemaMigrationTest extends TestCase
{
    private const REQUIRED_DATABASE = 'mkdpro_control_provisioning_test';

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql requis pour ProvisioningSchemaMigrationTest.');
        }

        try {
            $this->assertProvisioningTestConnectionTarget();
            $this->ensureDatabaseExists();
            $this->ensureSchemaMigrated();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Base provisioning_test indisponible : '.$e->getMessage());
        }
    }

    protected function connection(): string
    {
        return 'provisioning_test';
    }

    private function assertProvisioningTestConnectionTarget(): void
    {
        $database = config('database.connections.provisioning_test.database');
        $this->assertSame(
            self::REQUIRED_DATABASE,
            $database,
            'La connexion provisioning_test doit cibler '.self::REQUIRED_DATABASE.' uniquement.'
        );

        $devDatabase = config('database.connections.mysql.database');
        $this->assertNotSame(
            self::REQUIRED_DATABASE,
            $devDatabase,
            'mkdpro_control ne doit pas être la base par défaut mysql lors de ce test.'
        );
    }

    private function ensureDatabaseExists(): void
    {
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
    }

    private function ensureSchemaMigrated(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $currentDb = DB::connection($this->connection())->selectOne('SELECT DATABASE() AS db');
        $this->assertSame(self::REQUIRED_DATABASE, $currentDb->db);

        Artisan::call('migrate', [
            '--database' => $this->connection(),
            '--force' => true,
        ]);

        self::$schemaReady = true;
    }

    public function test_provisioning_runs_table_exists_on_dedicated_database(): void
    {
        $this->assertTrue(Schema::connection($this->connection())->hasTable('provisioning_runs'));
    }

    public function test_provisioning_run_steps_table_exists_on_dedicated_database(): void
    {
        $this->assertTrue(Schema::connection($this->connection())->hasTable('provisioning_run_steps'));
    }

    public function test_provisioning_runs_has_expected_columns(): void
    {
        $columns = Schema::connection($this->connection())->getColumnListing('provisioning_runs');

        foreach ([
            'id', 'installation_id', 'status', 'trigger', 'requested_by', 'target_version',
            'target_commit', 'pipeline_version', 'current_step', 'error_code', 'error_message',
            'retry_of_run_id', 'metadata', 'started_at', 'finished_at', 'active_installation_key',
            'created_at', 'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns, $column);
        }

        $this->assertNotContains('failed_at', $columns);
        $this->assertNotContains('readiness_level', $columns);
    }

    public function test_provisioning_run_steps_has_expected_columns(): void
    {
        $columns = Schema::connection($this->connection())->getColumnListing('provisioning_run_steps');

        foreach ([
            'id', 'provisioning_run_id', 'step_key', 'step_order', 'status', 'attempt',
            'started_at', 'finished_at', 'error_code', 'error_message', 'input_summary',
            'output_summary', 'metadata', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns, $column);
        }
    }

    public function test_active_installation_key_is_stored_generated_column(): void
    {
        $row = DB::connection($this->connection())->selectOne(
            'SELECT GENERATION_EXPRESSION AS expr, EXTRA AS extra
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::REQUIRED_DATABASE, 'provisioning_runs', 'active_installation_key'],
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('pending', strtolower((string) $row->expr));
        $this->assertStringContainsString('running', strtolower((string) $row->expr));
        $this->assertStringContainsString('STORED', strtoupper((string) $row->extra));
    }

    public function test_unique_index_on_active_installation_key_exists(): void
    {
        $indexes = DB::connection($this->connection())->select(
            'SHOW INDEX FROM provisioning_runs WHERE Key_name = ?',
            ['uq_pr_one_active_per_installation'],
        );

        $this->assertNotEmpty($indexes);
    }

    public function test_foreign_keys_use_restrict_on_delete(): void
    {
        $fks = DB::connection($this->connection())->select(
            'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN (?, ?)',
            [self::REQUIRED_DATABASE, 'provisioning_runs', 'provisioning_run_steps'],
        );

        $this->assertNotEmpty($fks);

        $mustRestrict = [
            'provisioning_runs_installation_id_foreign',
            'fk_pr_retry_of',
            'provisioning_run_steps_provisioning_run_id_foreign',
        ];

        foreach ($fks as $fk) {
            if (! in_array($fk->CONSTRAINT_NAME, $mustRestrict, true)) {
                continue;
            }

            $this->assertSame(
                'RESTRICT',
                $fk->DELETE_RULE,
                $fk->CONSTRAINT_NAME.' doit être RESTRICT, pas CASCADE.',
            );
        }

        $requestedBy = collect($fks)->firstWhere('CONSTRAINT_NAME', 'provisioning_runs_requested_by_foreign');
        $this->assertNotNull($requestedBy);
        $this->assertSame('SET NULL', $requestedBy->DELETE_RULE);
    }

    public function test_json_columns_accept_non_sensitive_payload(): void
    {
        $installationId = $this->insertMinimalInstallation('json-test-'.uniqid());

        $runId = DB::connection($this->connection())->table('provisioning_runs')->insertGetId([
            'installation_id' => $installationId,
            'status' => 'succeeded',
            'trigger' => 'manual',
            'metadata' => json_encode(['http_status' => 200, 'duration_ms' => 42]),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection($this->connection())->table('provisioning_run_steps')->insert([
            'provisioning_run_id' => $runId,
            'step_key' => 'validate',
            'step_order' => 1,
            'status' => 'succeeded',
            'input_summary' => json_encode(['checks' => 3]),
            'output_summary' => json_encode(['validation_passed' => true]),
            'metadata' => json_encode(['pipeline' => 'test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run = DB::connection($this->connection())->table('provisioning_runs')->find($runId);
        $step = DB::connection($this->connection())->table('provisioning_run_steps')
            ->where('provisioning_run_id', $runId)
            ->first();

        $this->assertSame(200, json_decode($run->metadata, true)['http_status']);
        $this->assertTrue(json_decode($step->output_summary, true)['validation_passed']);
    }

    public function test_retry_of_run_self_reference_is_allowed(): void
    {
        $installationId = $this->insertMinimalInstallation('retry-'.uniqid());

        $runA = DB::connection($this->connection())->table('provisioning_runs')->insertGetId([
            'installation_id' => $installationId,
            'status' => 'failed',
            'trigger' => 'manual',
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runB = DB::connection($this->connection())->table('provisioning_runs')->insertGetId([
            'installation_id' => $installationId,
            'status' => 'pending',
            'trigger' => 'retry',
            'retry_of_run_id' => $runA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertGreaterThan(0, $runB);
    }

    public function test_deleting_run_referenced_by_retry_is_rejected(): void
    {
        $installationId = $this->insertMinimalInstallation('retry-del-'.uniqid());

        $runA = DB::connection($this->connection())->table('provisioning_runs')->insertGetId([
            'installation_id' => $installationId,
            'status' => 'failed',
            'trigger' => 'manual',
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'pending',
            'trigger' => 'retry',
            'retry_of_run_id' => $runA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::connection($this->connection())->table('provisioning_runs')->where('id', $runA)->delete();
    }

    public function test_deleting_installation_with_run_is_rejected(): void
    {
        $installationId = $this->insertMinimalInstallation('inst-del-'.uniqid());

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'succeeded',
            'trigger' => 'manual',
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::connection($this->connection())->table('installations')->where('id', $installationId)->delete();
    }

    public function test_two_pending_runs_same_installation_rejected_by_unique_constraint(): void
    {
        $installationId = $this->insertMinimalInstallation('conc-pending-'.uniqid());

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'pending',
            'trigger' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'pending',
            'trigger' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pending_plus_running_same_installation_rejected(): void
    {
        $installationId = $this->insertMinimalInstallation('conc-mix-'.uniqid());

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'pending',
            'trigger' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'running',
            'trigger' => 'manual',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_two_terminal_runs_same_installation_allowed(): void
    {
        $installationId = $this->insertMinimalInstallation('term-'.uniqid());
        $now = now();

        foreach (['succeeded', 'failed'] as $status) {
            DB::connection($this->connection())->table('provisioning_runs')->insert([
                'installation_id' => $installationId,
                'status' => $status,
                'trigger' => 'manual',
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $count = DB::connection($this->connection())->table('provisioning_runs')
            ->where('installation_id', $installationId)
            ->count();

        $this->assertSame(2, $count);
    }

    public function test_new_pending_allowed_after_succeeded_failed_or_manual_terminal(): void
    {
        $installationId = $this->insertMinimalInstallation('after-term-'.uniqid());
        $now = now();

        foreach (['succeeded', 'failed', 'manual_intervention_required'] as $terminalStatus) {
            DB::connection($this->connection())->table('provisioning_runs')->insert([
                'installation_id' => $installationId,
                'status' => $terminalStatus,
                'trigger' => 'manual',
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::connection($this->connection())->table('provisioning_runs')->insert([
                'installation_id' => $installationId,
                'status' => 'pending',
                'trigger' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::connection($this->connection())->table('provisioning_runs')
                ->where('installation_id', $installationId)
                ->where('status', 'pending')
                ->delete();

            DB::connection($this->connection())->table('provisioning_runs')
                ->where('installation_id', $installationId)
                ->where('status', $terminalStatus)
                ->delete();
        }

        $this->assertTrue(true);
    }

    public function test_running_then_pending_same_installation_rejected(): void
    {
        $installationId = $this->insertMinimalInstallation('run-pend-'.uniqid());

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'running',
            'trigger' => 'manual',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::connection($this->connection())->table('provisioning_runs')->insert([
            'installation_id' => $installationId,
            'status' => 'pending',
            'trigger' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_provisioning_migrations_rollback_on_dedicated_database_only(): void
    {
        $this->assertTrue(Schema::connection($this->connection())->hasTable('provisioning_run_steps'));
        $this->assertTrue(Schema::connection($this->connection())->hasTable('provisioning_runs'));
        $this->assertTrue(Schema::connection($this->connection())->hasTable('installations'));

        Artisan::call('migrate:rollback', [
            '--database' => $this->connection(),
            '--step' => 2,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection($this->connection())->hasTable('provisioning_run_steps'));
        $this->assertFalse(Schema::connection($this->connection())->hasTable('provisioning_runs'));
        $this->assertTrue(Schema::connection($this->connection())->hasTable('installations'));

        Artisan::call('migrate', [
            '--database' => $this->connection(),
            '--force' => true,
        ]);

        self::$schemaReady = true;

        $this->assertTrue(Schema::connection($this->connection())->hasTable('provisioning_runs'));
    }

    public function test_mkdpro_control_development_database_has_no_provisioning_tables(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Connexion dev mysql non active dans cet environnement de test.');
        }

        $devDb = config('database.connections.mysql.database');

        if ($devDb !== 'mkdpro_control') {
            $this->markTestSkipped('Ce contrôle s’applique lorsque DB_DATABASE=mkdpro_control.');
        }

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('Connexion mysql dev indisponible.');
        }

        $this->assertFalse(Schema::connection('mysql')->hasTable('provisioning_runs'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('provisioning_run_steps'));
    }

    public function test_provisioning_eloquent_models_exist_without_engine(): void
    {
        $this->assertTrue(class_exists(\App\Models\ProvisioningRun::class));
        $this->assertTrue(class_exists(\App\Models\ProvisioningRunStep::class));
        $this->assertFalse(class_exists(\App\Services\ProvisioningService::class));
    }

    private function insertMinimalInstallation(string $subdomainSuffix): int
    {
        $now = now();

        $clientId = DB::connection($this->connection())->table('clients')->insertGetId([
            'company_name' => 'Provisioning Schema Test Co',
            'contact_name' => 'Test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::connection($this->connection())->table('installations')->insertGetId([
            'client_id' => $clientId,
            'name' => 'Test Installation',
            'subdomain' => $subdomainSuffix,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
