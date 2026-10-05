<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDependenciesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\NullO2SwitchDependenciesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesConfiguration;
use App\Services\Provisioning\Steps\DependenciesProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchDependenciesGateway;
use Tests\TestCase;

class O2SwitchDependenciesAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.dependencies', [
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

        $adapter = app(DependencyInstallationAdapter::class);
        $this->assertInstanceOf(O2SwitchDependenciesAdapter::class, $adapter);

        $result = $adapter->installDependencies($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_dependencies_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchDependenciesAdapter(
            new NullO2SwitchDependenciesGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $result = $adapter->installDependencies($this->makeContext());

        $this->assertSame('o2switch_dependencies_disabled', $result->code);
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchDependenciesAdapter(
            new NullO2SwitchDependenciesGateway,
            new O2SwitchDependenciesConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                deploymentRootBase: '',
                cpanelHost: '',
                gestionPhpVersionMinimum: '8.2',
                nodeDependenciesRequired: true,
            ),
        );

        $result = $adapter->installDependencies($this->makeContext());

        $this->assertSame('o2switch_dependencies_not_configured', $result->code);
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchDependenciesAdapter(
            new NullO2SwitchDependenciesGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->installDependencies($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertContains('composer_install', $result->outputSummary['operations_planned'] ?? []);
        $this->assertContains('npm_ci', $result->outputSummary['operations_planned'] ?? []);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchDependenciesAdapter(
            new NullO2SwitchDependenciesGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->installDependencies($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_dependencies_protocol_pending', $result->code);
    }

    public function test_runtime_incompatible_php_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchDependenciesAdapter(
            new NullO2SwitchDependenciesGateway,
            $this->dryRunConfiguration(),
        );

        $context = $this->makeContext(externalReferences: [
            'reported_php_version' => '8.0.0',
        ]);

        $result = $adapter->installDependencies($context);

        $this->assertSame('o2switch_dependencies_runtime_incompatible', $result->code);
    }

    public function test_fake_gateway_composer_success(): void
    {
        $fake = new FakeO2SwitchDependenciesGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operations_completed' => ['composer_install', 'npm_ci'],
            ],
        );

        $adapter = new O2SwitchDependenciesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->installDependencies($this->makeContext());

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
    }

    public function test_fake_gateway_idempotent_replay(): void
    {
        $fake = new FakeO2SwitchDependenciesGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operations_completed' => ['composer_install', 'npm_ci'],
                'idempotent_replay' => true,
            ],
            metadata: ['idempotent_replay' => true],
        );

        $adapter = new O2SwitchDependenciesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->installDependencies($this->makeContext());

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_fake_gateway_composer_failure(): void
    {
        $fake = new FakeO2SwitchDependenciesGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_dependencies_composer_failed',
            'Composer install simulé en échec.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchDependenciesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->installDependencies($this->makeContext());

        $this->assertSame('o2switch_dependencies_composer_failed', $result->code);
    }

    public function test_fake_gateway_npm_failure(): void
    {
        $fake = new FakeO2SwitchDependenciesGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_dependencies_npm_failed',
            'npm ci simulé en échec.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchDependenciesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->installDependencies($this->makeContext());

        $this->assertSame('o2switch_dependencies_npm_failed', $result->code);
    }

    public function test_fake_gateway_propagates_through_dependencies_step(): void
    {
        $fake = new FakeO2SwitchDependenciesGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_dependencies_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchDependenciesGateway::class, $fake);
        $this->app->instance(DependencyInstallationAdapter::class, new O2SwitchDependenciesAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(DependenciesProvisioningStep::class);

        $result = app(DependenciesProvisioningStep::class)->execute($this->makeContext());

        $this->assertSame('o2switch_dependencies_manual', $result->code);
    }

    public function test_results_never_expose_secrets_or_full_shell_commands_with_tokens(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-deps-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchDependenciesAdapter(new NullO2SwitchDependenciesGateway, $this->dryRunConfiguration());
        $result = $adapter->installDependencies($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-deps-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('DB_PASSWORD', (string) $encoded);
    }

    private function dryRunConfiguration(bool $enabled = true): O2SwitchDependenciesConfiguration
    {
        return new O2SwitchDependenciesConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            gestionPhpVersionMinimum: '8.2',
            nodeDependenciesRequired: true,
        );
    }

    private function liveReadyConfiguration(): O2SwitchDependenciesConfiguration
    {
        return new O2SwitchDependenciesConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: 'panel.example.test',
            gestionPhpVersionMinimum: '8.2',
            nodeDependenciesRequired: true,
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContext(array $externalReferences = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Dependencies Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Dependencies Installation',
            'subdomain' => 'deps-'.uniqid(),
            'status' => 'active',
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
            externalReferences: $externalReferences,
        );
    }
}
