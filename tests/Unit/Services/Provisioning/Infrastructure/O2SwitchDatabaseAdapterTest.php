<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDatabaseGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\CpanelUapiO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\NullO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;
use App\Services\Provisioning\Steps\DatabaseProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchDatabaseGateway;
use Tests\TestCase;

class O2SwitchDatabaseAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.database', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'database_name_prefix' => '',
            'mysql_host_logical' => 'localhost',
            'cpanel_host' => '',
        ]);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(ClientDatabaseAdapter::class);
        $this->assertInstanceOf(O2SwitchDatabaseAdapter::class, $adapter);

        $result = $adapter->provisionDatabase($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(
            InfrastructureAdapterResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
        $this->assertSame('o2switch_database_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        Http::fake();

        $adapter = new O2SwitchDatabaseAdapter(
            new NullO2SwitchDatabaseGateway,
            new O2SwitchDatabaseConfiguration(
                provider: 'o2switch',
                enabled: false,
                dryRun: false,
                accountLogicalId: 'acct-demo',
                databaseNamePrefix: 'cpuser_',
                mysqlHostLogical: 'localhost',
                cpanelHost: '',
            ),
        );

        $result = $adapter->provisionDatabase($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_database_disabled', $result->code);
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchDatabaseAdapter(
            new NullO2SwitchDatabaseGateway,
            new O2SwitchDatabaseConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                databaseNamePrefix: '',
                mysqlHostLogical: 'localhost',
                cpanelHost: '',
            ),
        );

        $result = $adapter->provisionDatabase($this->makeContext());

        $this->assertSame('o2switch_database_not_configured', $result->code);
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchDatabaseAdapter(
            new NullO2SwitchDatabaseGateway,
            new O2SwitchDatabaseConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: true,
                accountLogicalId: 'o2switch-account-ref',
                databaseNamePrefix: 'cpuser_',
                mysqlHostLogical: 'localhost',
                cpanelHost: '',
            ),
        );

        $result = $adapter->provisionDatabase($this->makeContext(['subdomain' => 'clientx']));

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertTrue($result->outputSummary['dry_run'] ?? false);
        $this->assertStringStartsWith('cpuser_', $result->outputSummary['database_name'] ?? '');
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchDatabaseAdapter(
            new NullO2SwitchDatabaseGateway,
            new O2SwitchDatabaseConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: 'o2switch-account-ref',
                databaseNamePrefix: 'cpuser_',
                mysqlHostLogical: 'localhost',
                cpanelHost: 'panel.example.test',
            ),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->provisionDatabase($this->makeContext(['subdomain' => 'clientx']));

        Http::assertNothingSent();
        $this->assertSame('o2switch_database_protocol_pending', $result->code);
    }

    public function test_cpanel_gateway_access_denied_without_real_network(): void
    {
        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response([], 403),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelUapiO2SwitchDatabaseGateway;
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: 'panel.example.test',
        );

        $result = $gateway->provisionDatabase($this->makeContext(['subdomain' => 'clientx']), $configuration);

        $this->assertSame('o2switch_database_access_denied', $result->code);
        Http::assertSentCount(1);
    }

    public function test_cpanel_gateway_creates_database_when_absent(): void
    {
        $context = $this->makeContext(['subdomain' => 'clientx']);
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: 'panel.example.test',
        );
        $expectedName = \App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseNaming::resolve(
            $context,
            $configuration,
        )?->fullDatabaseName;
        $this->assertNotNull($expectedName);

        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::sequence()
                ->push(['result' => ['data' => []]], 200)
                ->push(['result' => ['data' => [$expectedName]]], 200),
            '*2083/execute/Mysql/create_database*' => Http::response(['result' => ['status' => 1]], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelUapiO2SwitchDatabaseGateway;
        $result = $gateway->provisionDatabase($context, $configuration);

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['created'] ?? false);
    }

    public function test_cpanel_gateway_existing_database_is_idempotent(): void
    {
        $context = $this->makeContext(['subdomain' => 'clientx']);
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: 'panel.example.test',
        );
        $expectedName = \App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseNaming::resolve(
            $context,
            $configuration,
        )?->fullDatabaseName;
        $this->assertNotNull($expectedName);

        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response([
                'result' => ['data' => [$expectedName]],
            ], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelUapiO2SwitchDatabaseGateway;
        $result = $gateway->provisionDatabase($context, $configuration);

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
        Http::assertSentCount(1);
    }

    public function test_cpanel_gateway_detects_incompatible_expected_database_name(): void
    {
        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response([
                'result' => ['data' => ['cpuser_other_db']],
            ], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelUapiO2SwitchDatabaseGateway;
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: 'panel.example.test',
        );

        $context = $this->makeContext([
            'subdomain' => 'clientx',
            'database_name' => 'cpuser_expected_missing',
        ]);

        $result = $gateway->provisionDatabase($context, $configuration);

        $this->assertSame('o2switch_database_incompatible', $result->code);
    }

    public function test_fake_gateway_error_propagates_through_database_step(): void
    {
        $fake = new FakeO2SwitchDatabaseGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_database_gateway_error',
            'Erreur simulée.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $this->app->instance(O2SwitchDatabaseGateway::class, $fake);
        $this->app->instance(ClientDatabaseAdapter::class, new O2SwitchDatabaseAdapter(
            $fake,
            new O2SwitchDatabaseConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: 'acct',
                databaseNamePrefix: 'cpuser_',
                mysqlHostLogical: 'localhost',
                cpanelHost: 'panel.example.test',
            ),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(DatabaseProvisioningStep::class);

        $result = app(DatabaseProvisioningStep::class)->execute($this->makeContext());

        $this->assertSame('o2switch_database_gateway_error', $result->code);
        $this->assertCount(1, $fake->calls);
    }

    public function test_results_never_expose_api_token_or_password(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-o2switch-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user');

        $adapter = new O2SwitchDatabaseAdapter(new NullO2SwitchDatabaseGateway);
        $result = $adapter->provisionDatabase($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-o2switch-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user', (string) $encoded);
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     */
    private function makeContext(array $installationOverrides = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'DB Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'DB Installation',
            'subdomain' => 'db-'.uniqid(),
            'status' => 'active',
        ], $installationOverrides));

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);
        $installation->setRelation('client', $client);

        return ProvisioningContext::fromRun($run);
    }
}
