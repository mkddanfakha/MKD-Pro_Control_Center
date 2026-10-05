<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchEnvironmentGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\CpanelFileUapiO2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\NullO2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchGestionEnvAssembly;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchGestionEnvSpecification;
use App\Services\Provisioning\Steps\EnvironmentProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchEnvironmentGateway;
use Tests\TestCase;

class O2SwitchEnvironmentAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.environment', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'deployment_root_base' => '',
            'gestion_app_base_domain' => '',
            'application_env' => 'production',
            'application_debug' => false,
            'cpanel_host' => '',
        ]);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(EnvironmentConfigurationAdapter::class);
        $this->assertInstanceOf(O2SwitchEnvironmentAdapter::class, $adapter);

        $result = $adapter->configureEnvironment($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_environment_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $result = $adapter->configureEnvironment($this->makeContext());

        $this->assertSame('o2switch_environment_disabled', $result->code);
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            new O2SwitchEnvironmentConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                deploymentRootBase: '',
                gestionAppBaseDomain: '',
                applicationEnv: 'production',
                applicationDebug: false,
                cpanelHost: '',
            ),
        );

        $result = $adapter->configureEnvironment($this->makeContext());

        $this->assertSame('o2switch_environment_not_configured', $result->code);
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureEnvironment($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertSame('pending_generation', $result->metadata['app_key_state'] ?? null);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureEnvironment($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_environment_protocol_pending', $result->code);
    }

    public function test_valid_app_url_from_installation_domain(): void
    {
        $assembly = O2SwitchGestionEnvAssembly::assemble(
            $this->makeContext(['domain' => 'clientx.mkd-pro.com', 'subdomain' => 'clientx-domain-test']),
            $this->dryRunConfiguration(),
        );

        $this->assertNotNull($assembly['result']);
        $this->assertSame('https://clientx.mkd-pro.com', $assembly['result']->appUrl);
    }

    public function test_invalid_app_url_when_domain_and_base_missing(): void
    {
        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            new O2SwitchEnvironmentConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: true,
                accountLogicalId: 'o2switch-account-ref',
                deploymentRootBase: '/home/cpuser',
                gestionAppBaseDomain: '',
                applicationEnv: 'production',
                applicationDebug: false,
                cpanelHost: '',
            ),
        );

        $context = $this->makeContext(['domain' => null, 'subdomain' => 'clientx']);
        $result = $adapter->configureEnvironment($context);

        $this->assertSame('o2switch_environment_invalid_app_url', $result->code);
    }

    public function test_valid_database_configuration_from_context(): void
    {
        $assembly = O2SwitchGestionEnvAssembly::assemble(
            $this->makeContext(),
            $this->dryRunConfiguration(),
        );

        $this->assertNotNull($assembly['result']);
        $preview = $assembly['result']->builder->publicPreview();
        $this->assertSame('mysql', $preview['DB_CONNECTION']);
        $this->assertSame('cpuser_gestion_db', $preview['DB_DATABASE']);
        $this->assertSame('gestion_user', $preview['DB_USERNAME']);
    }

    public function test_db_password_and_app_key_never_leak_in_adapter_result(): void
    {
        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureEnvironment($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString(O2SwitchGestionEnvSpecification::DB_PASSWORD_PENDING_SENTINEL, (string) $encoded);
        $this->assertStringNotContainsString(O2SwitchGestionEnvSpecification::APP_KEY_PENDING_SENTINEL, (string) $encoded);
        $this->assertStringNotContainsString('super-secret-db-pass', (string) $encoded);
    }

    public function test_forbidden_configuration_key_is_rejected(): void
    {
        $adapter = new O2SwitchEnvironmentAdapter(
            new NullO2SwitchEnvironmentGateway,
            $this->dryRunConfiguration(),
        );

        $context = $this->makeContext(configuration: [
            'CUSTOM_UNAUTHORIZED' => 'must-not-apply',
        ]);

        $result = $adapter->configureEnvironment($context);

        $this->assertSame('o2switch_environment_forbidden_key', $result->code);
    }

    public function test_fake_gateway_idempotent_success(): void
    {
        $fake = new FakeO2SwitchEnvironmentGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'env_keys_applied' => ['APP_URL'],
                'idempotent_replay' => true,
            ],
        );

        $this->app->instance(O2SwitchEnvironmentGateway::class, $fake);
        $this->app->instance(EnvironmentConfigurationAdapter::class, new O2SwitchEnvironmentAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(EnvironmentProvisioningStep::class);

        $result = app(EnvironmentProvisioningStep::class)->execute($this->makeContext());

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
    }

    public function test_cpanel_gateway_writes_env_when_absent(): void
    {
        Http::fake([
            '*2083/execute/Fileman/get_file_content*' => Http::response(['result' => ['data' => '']], 404),
            '*2083/execute/Fileman/save_file_content*' => Http::response(['result' => ['status' => 1]], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $assembly = O2SwitchGestionEnvAssembly::assemble($this->makeContext(), $this->liveReadyConfiguration());
        $this->assertNotNull($assembly['result']);

        $gateway = new CpanelFileUapiO2SwitchEnvironmentGateway;
        $result = $gateway->configureEnvironment(
            $this->makeContext(),
            $this->liveReadyConfiguration(),
            $assembly['result'],
        );

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['written'] ?? false);
    }

    public function test_cpanel_gateway_detects_idempotent_existing_file(): void
    {
        $assembly = O2SwitchGestionEnvAssembly::assemble($this->makeContext(), $this->liveReadyConfiguration());
        $this->assertNotNull($assembly['result']);
        $content = $assembly['result']->builder->render();

        Http::fake([
            '*2083/execute/Fileman/get_file_content*' => Http::response(['result' => ['data' => $content]], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelFileUapiO2SwitchEnvironmentGateway;
        $result = $gateway->configureEnvironment(
            $this->makeContext(),
            $this->liveReadyConfiguration(),
            $assembly['result'],
        );

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
        Http::assertSentCount(1);
    }

    private function dryRunConfiguration(bool $enabled = true): O2SwitchEnvironmentConfiguration
    {
        return new O2SwitchEnvironmentConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            gestionAppBaseDomain: 'mkd-pro.com',
            applicationEnv: 'production',
            applicationDebug: false,
            cpanelHost: '',
        );
    }

    private function liveReadyConfiguration(): O2SwitchEnvironmentConfiguration
    {
        return new O2SwitchEnvironmentConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            gestionAppBaseDomain: 'mkd-pro.com',
            applicationEnv: 'production',
            applicationDebug: false,
            cpanelHost: 'panel.example.test',
        );
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     * @param  array<string, mixed>  $configuration
     */
    /**
     * @param  array<string, mixed>  $installationOverrides
     * @param  array<string, mixed>  $configuration
     */
    private function makeContext(
        array $installationOverrides = [],
        array $configuration = [],
    ): ProvisioningContext {
        $client = Client::query()->create([
            'company_name' => 'Environment Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Environment Installation',
            'subdomain' => 'clientx-'.uniqid(),
            'domain' => null,
            'database_name' => 'cpuser_gestion_db',
            'database_host' => 'localhost',
            'status' => 'active',
        ], $installationOverrides));

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);
        $installation->setRelation('client', $client);

        return new ProvisioningContext(
            provisioningRun: $run,
            installation: $installation,
            targetVersion: $run->target_version,
            targetCommit: $run->target_commit,
            pipelineVersion: $run->pipeline_version,
            configuration: $configuration,
            externalReferences: [
                'database_username' => 'gestion_user',
            ],
        );
    }
}
