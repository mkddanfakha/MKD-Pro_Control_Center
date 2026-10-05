<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchMigrateGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\NullO2SwitchMigrateGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateConfiguration;
use App\Services\Provisioning\Steps\MigrateProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchMigrateGateway;
use Tests\TestCase;

class O2SwitchMigrateAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.migrate', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'deployment_root_base' => '',
            'cpanel_host' => '',
        ]);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(DatabaseMigrationAdapter::class);
        $this->assertInstanceOf(O2SwitchMigrateAdapter::class, $adapter);

        $result = $adapter->runMigrations($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_migrate_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_migrate_disabled',
            $adapter->runMigrations($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            new O2SwitchMigrateConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                deploymentRootBase: '',
                cpanelHost: '',
                forbiddenMigrationDatabaseNames: [],
            ),
        );

        $this->assertSame(
            'o2switch_migrate_not_configured',
            $adapter->runMigrations($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertSame('artisan_migrate_force', $result->outputSummary['operation_planned'] ?? null);
        $this->assertSame('gestion_client_db_a', $result->metadata['migration_target_database_name'] ?? null);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->runMigrations($this->makeContext([], [
            'gestion_environment_prepared' => true,
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_migrate_protocol_pending', $result->code);
    }

    public function test_installation_without_database_name_rejects_target(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContext(
            databaseName: null,
            databaseHost: 'localhost',
        ));

        $this->assertSame('o2switch_migrate_database_target_invalid', $result->code);
    }

    public function test_installation_without_database_host_rejects_target(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContext(
            databaseName: 'gestion_client_db_a',
            databaseHost: null,
        ));

        $this->assertSame('o2switch_migrate_database_target_invalid', $result->code);
    }

    public function test_control_center_database_name_is_forbidden_target(): void
    {
        config()->set('provisioning.security.forbidden_migration_database_names', ['control_center_main_db']);

        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(forbiddenNames: ['control_center_main_db']),
        );

        $result = $adapter->runMigrations($this->makeContext(
            databaseName: 'control_center_main_db',
            databaseHost: 'localhost',
        ));

        $this->assertSame('o2switch_migrate_database_target_invalid', $result->code);
    }

    public function test_external_reference_database_name_mismatch_rejects_target(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContext([], [
            'database_name' => 'other_installation_db',
        ]));

        $this->assertSame('o2switch_migrate_database_target_invalid', $result->code);
    }

    public function test_installation_a_cannot_be_redirected_to_installation_b_database_via_external_reference(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Migrate Cross Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installationB = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation B',
            'subdomain' => 'migrate-b-'.uniqid(),
            'status' => 'active',
            'database_name' => 'gestion_client_db_b_only',
            'database_host' => 'localhost',
        ]);

        $installationA = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation A',
            'subdomain' => 'migrate-a-'.uniqid(),
            'status' => 'active',
            'database_name' => 'gestion_client_db_a_only',
            'database_host' => 'localhost',
        ]);

        $this->assertNotSame($installationA->database_name, $installationB->database_name);

        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContextForInstallation($installationA, [
            'migration_database_name' => $installationB->database_name,
        ]));

        $this->assertSame('o2switch_migrate_database_target_invalid', $result->code);
    }

    public function test_external_migration_target_installation_id_must_match_context(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContext([], [
            'migration_target_installation_id' => 999999,
        ]));

        $this->assertSame('o2switch_migrate_database_target_invalid', $result->code);
    }

    public function test_live_mode_without_environment_prerequisites_returns_not_ready(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->runMigrations($this->makeContext());

        $this->assertSame('o2switch_migrate_environment_not_ready', $result->code);
    }

    public function test_fake_gateway_migration_success(): void
    {
        $fake = new FakeO2SwitchMigrateGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['operation_completed' => 'artisan_migrate_force'],
            metadata: ['migration_state' => 'applied'],
        );

        $adapter = new O2SwitchMigrateAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->runMigrations($this->makeContext([], [
            'gestion_environment_prepared' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('gestion_client_db_a', $fake->calls[0]['migration_target_database_name']);
    }

    public function test_fake_gateway_idempotent_already_up_to_date(): void
    {
        $fake = new FakeO2SwitchMigrateGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operation_completed' => 'artisan_migrate_force',
                'idempotent_replay' => true,
            ],
            metadata: ['migration_state' => 'already_up_to_date'],
        );

        $adapter = new O2SwitchMigrateAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->runMigrations($this->makeContext([], [
            'gestion_environment_prepared' => true,
        ]));

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
        $this->assertSame('already_up_to_date', $result->metadata['migration_state'] ?? null);
    }

    public function test_fake_gateway_partial_migration_failure(): void
    {
        $fake = new FakeO2SwitchMigrateGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_migrate_partial',
            'Migrations partiellement appliquées.',
            metadata: ['migration_state' => 'partial'],
        );

        $adapter = new O2SwitchMigrateAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->runMigrations($this->makeContext([], [
            'gestion_environment_prepared' => true,
        ]));

        $this->assertSame('o2switch_migrate_partial', $result->code);
    }

    public function test_fake_gateway_migration_failure(): void
    {
        $fake = new FakeO2SwitchMigrateGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_migrate_artisan_failed',
            'Migration simulée en échec.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchMigrateAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->runMigrations($this->makeContext([], [
            'gestion_environment_prepared' => true,
        ]));

        $this->assertSame('o2switch_migrate_artisan_failed', $result->code);
    }

    public function test_fake_gateway_propagates_through_migrate_step(): void
    {
        $fake = new FakeO2SwitchMigrateGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_migrate_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchMigrateGateway::class, $fake);
        $this->app->instance(DatabaseMigrationAdapter::class, new O2SwitchMigrateAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(MigrateProvisioningStep::class);

        $result = app(MigrateProvisioningStep::class)->execute($this->makeContext([], [
            'gestion_environment_prepared' => true,
        ]));

        $this->assertSame('o2switch_migrate_manual', $result->code);
    }

    public function test_provisioning_context_rejects_forbidden_secret_keys_in_external_references(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeContext([], ['DB_PASSWORD' => 'must-not-appear']);
    }

    public function test_results_never_expose_secrets_in_adapter_output(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-migrate-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchMigrateAdapter(new NullO2SwitchMigrateGateway, $this->dryRunConfiguration());
        $result = $adapter->runMigrations($this->makeContext([], [
            'database_username' => 'mysql_user_ref',
        ]));

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-migrate-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('DB_PASSWORD', (string) $encoded);
    }

    public function test_command_plan_uses_migrate_force_not_destructive_commands(): void
    {
        $adapter = new O2SwitchMigrateAdapter(
            new NullO2SwitchMigrateGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->runMigrations($this->makeContext());

        $this->assertSame('artisan_migrate_force', $result->outputSummary['operation_planned'] ?? null);
        $this->assertSame('migrate_force', $result->metadata['laravel_migrate_policy'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     * @param  list<string>  $forbiddenNames
     */
    private function dryRunConfiguration(bool $enabled = true, array $forbiddenNames = []): O2SwitchMigrateConfiguration
    {
        return new O2SwitchMigrateConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenMigrationDatabaseNames: $forbiddenNames,
        );
    }

    private function liveReadyConfiguration(): O2SwitchMigrateConfiguration
    {
        return new O2SwitchMigrateConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: 'panel.example.test',
            forbiddenMigrationDatabaseNames: [],
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContext(
        array $externalReferences = [],
        ?array $moreExternal = null,
        ?string $databaseName = 'gestion_client_db_a',
        ?string $databaseHost = 'localhost',
    ): ProvisioningContext {
        $refs = $moreExternal !== null ? array_merge($externalReferences, $moreExternal) : $externalReferences;

        $client = Client::query()->create([
            'company_name' => 'Migrate Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Migrate Installation',
            'subdomain' => 'migrate-'.uniqid(),
            'status' => 'active',
            'database_name' => $databaseName,
            'database_host' => $databaseHost,
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);

        return new ProvisioningContext(
            provisioningRun: $run,
            installation: $installation,
            targetVersion: $run->target_version,
            targetCommit: $run->target_commit,
            pipelineVersion: $run->pipeline_version,
            externalReferences: $refs,
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContextForInstallation(Installation $installation, array $externalReferences = []): ProvisioningContext
    {
        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);

        return new ProvisioningContext(
            provisioningRun: $run,
            installation: $installation,
            targetVersion: $run->target_version,
            targetCommit: $run->target_commit,
            pipelineVersion: $run->pipeline_version,
            externalReferences: $externalReferences,
        );
    }
}
