<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Connexion MySQL mkdpro_control_provisioning_test uniquement (migrations provisioning).
 */
trait UsesProvisioningTestDatabase
{
    private const PROVISIONING_TEST_DATABASE = 'mkdpro_control_provisioning_test';

    private static bool $provisioningTestSchemaReady = false;

    protected function provisioningTestConnection(): string
    {
        return 'provisioning_test';
    }

    protected function setUpProvisioningTestDatabase(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql requis pour les tests provisioning MySQL.');
        }

        try {
            $this->assertProvisioningTestConnectionTarget();
            $this->ensureProvisioningTestDatabaseExists();
            $this->ensureProvisioningTestSchemaMigrated();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Base provisioning_test indisponible : '.$e->getMessage());
        }
    }

    private function assertProvisioningTestConnectionTarget(): void
    {
        $database = config('database.connections.provisioning_test.database');
        $this->assertSame(self::PROVISIONING_TEST_DATABASE, $database);
    }

    private function ensureProvisioningTestDatabaseExists(): void
    {
        $config = config('database.connections.provisioning_test');

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS `'.self::PROVISIONING_TEST_DATABASE.'` '
            .'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    private function ensureProvisioningTestSchemaMigrated(): void
    {
        if (self::$provisioningTestSchemaReady) {
            return;
        }

        $currentDb = DB::connection($this->provisioningTestConnection())->selectOne('SELECT DATABASE() AS db');
        $this->assertSame(self::PROVISIONING_TEST_DATABASE, $currentDb->db);

        Artisan::call('migrate', [
            '--database' => $this->provisioningTestConnection(),
            '--force' => true,
        ]);

        self::$provisioningTestSchemaReady = true;
    }

    protected function assertProvisioningTablesExist(): void
    {
        $connection = $this->provisioningTestConnection();
        $this->assertTrue(Schema::connection($connection)->hasTable('provisioning_runs'));
        $this->assertTrue(Schema::connection($connection)->hasTable('provisioning_run_steps'));
    }
}
