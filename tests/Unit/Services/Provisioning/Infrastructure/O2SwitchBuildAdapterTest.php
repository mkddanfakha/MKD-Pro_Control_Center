<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchBuildGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\NullO2SwitchBuildGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildConfiguration;
use App\Services\Provisioning\Steps\BuildProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchBuildGateway;
use Tests\TestCase;

class O2SwitchBuildAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.build', [
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

        $adapter = app(ApplicationBuildAdapter::class);
        $this->assertInstanceOf(O2SwitchBuildAdapter::class, $adapter);

        $result = $adapter->buildApplication($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_build_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchBuildAdapter(
            new NullO2SwitchBuildGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_build_disabled',
            $adapter->buildApplication($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchBuildAdapter(
            new NullO2SwitchBuildGateway,
            new O2SwitchBuildConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                deploymentRootBase: '',
                cpanelHost: '',
                npmBuildScript: 'build',
                artifactManifestRelativePath: 'public/build/manifest.json',
            ),
        );

        $this->assertSame(
            'o2switch_build_not_configured',
            $adapter->buildApplication($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchBuildAdapter(
            new NullO2SwitchBuildGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->buildApplication($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertSame('npm_run_build', $result->outputSummary['operation_planned'] ?? null);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchBuildAdapter(
            new NullO2SwitchBuildGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->buildApplication($this->makeContext([
            'vite_public_env_keys_present' => ['VITE_APP_NAME'],
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_build_protocol_pending', $result->code);
    }

    public function test_live_mode_without_vite_public_keys_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchBuildAdapter(
            new NullO2SwitchBuildGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->buildApplication($this->makeContext());

        $this->assertSame('o2switch_build_vite_env_missing', $result->code);
    }

    public function test_fake_gateway_build_success(): void
    {
        $fake = new FakeO2SwitchBuildGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['operation_completed' => 'npm_run_build'],
        );

        $adapter = new O2SwitchBuildAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->buildApplication($this->makeContext([
            'vite_public_env_keys_present' => ['VITE_APP_NAME'],
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
    }

    public function test_fake_gateway_idempotent_replay(): void
    {
        $fake = new FakeO2SwitchBuildGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operation_completed' => 'npm_run_build',
                'idempotent_replay' => true,
            ],
        );

        $adapter = new O2SwitchBuildAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->buildApplication($this->makeContext([
            'vite_public_env_keys_present' => ['VITE_APP_NAME'],
        ]));

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_fake_gateway_build_failure(): void
    {
        $fake = new FakeO2SwitchBuildGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_build_npm_failed',
            'Build Vite simulé en échec.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchBuildAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->buildApplication($this->makeContext([
            'vite_public_env_keys_present' => ['VITE_APP_NAME'],
        ]));

        $this->assertSame('o2switch_build_npm_failed', $result->code);
    }

    public function test_fake_gateway_propagates_through_build_step(): void
    {
        $fake = new FakeO2SwitchBuildGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_build_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchBuildGateway::class, $fake);
        $this->app->instance(ApplicationBuildAdapter::class, new O2SwitchBuildAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(BuildProvisioningStep::class);

        $result = app(BuildProvisioningStep::class)->execute($this->makeContext([
            'vite_public_env_keys_present' => ['VITE_APP_NAME'],
        ]));

        $this->assertSame('o2switch_build_manual', $result->code);
    }

    public function test_results_never_expose_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-build-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchBuildAdapter(new NullO2SwitchBuildGateway, $this->dryRunConfiguration());
        $result = $adapter->buildApplication($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-build-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('APP_KEY', (string) $encoded);
    }

    public function test_command_plan_uses_npm_run_build_not_dev(): void
    {
        $adapter = new O2SwitchBuildAdapter(
            new NullO2SwitchBuildGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->buildApplication($this->makeContext());

        $this->assertSame('npm_run_build', $result->outputSummary['operation_planned'] ?? null);
    }

    private function dryRunConfiguration(bool $enabled = true): O2SwitchBuildConfiguration
    {
        return new O2SwitchBuildConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            npmBuildScript: 'build',
            artifactManifestRelativePath: 'public/build/manifest.json',
        );
    }

    private function liveReadyConfiguration(): O2SwitchBuildConfiguration
    {
        return new O2SwitchBuildConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: 'panel.example.test',
            npmBuildScript: 'build',
            artifactManifestRelativePath: 'public/build/manifest.json',
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContext(array $externalReferences = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Build Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Build Installation',
            'subdomain' => 'build-'.uniqid(),
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
