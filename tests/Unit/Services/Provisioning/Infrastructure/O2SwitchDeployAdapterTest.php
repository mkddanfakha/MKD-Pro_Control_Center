<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDeployGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\CpanelGitUapiO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\NullO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Services\Provisioning\Steps\DeployProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchDeployGateway;
use Tests\TestCase;

class O2SwitchDeployAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.deploy', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'deployment_root_base' => '',
            'gestion_git_repository_url' => '',
            'default_git_ref' => '',
            'cpanel_host' => '',
        ]);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(ApplicationDeployAdapter::class);
        $this->assertInstanceOf(O2SwitchDeployAdapter::class, $adapter);

        $result = $adapter->deployApplication($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_deploy_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        Http::fake();

        $adapter = new O2SwitchDeployAdapter(
            new NullO2SwitchDeployGateway,
            $this->liveReadyConfiguration(enabled: false),
        );

        $result = $adapter->deployApplication($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_deploy_disabled', $result->code);
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchDeployAdapter(
            new NullO2SwitchDeployGateway,
            new O2SwitchDeployConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                deploymentRootBase: '',
                gestionGitRepositoryUrl: '',
                defaultGitRef: '',
                cpanelHost: '',
            ),
        );

        $result = $adapter->deployApplication($this->makeContext());

        $this->assertSame('o2switch_deploy_not_configured', $result->code);
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchDeployAdapter(
            new NullO2SwitchDeployGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->deployApplication($this->makeContext(['target_version' => '1.2.0']));

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertTrue($result->outputSummary['dry_run'] ?? false);
    }

    public function test_dry_run_rejects_invalid_deployment_root(): void
    {
        $adapter = new O2SwitchDeployAdapter(
            new NullO2SwitchDeployGateway,
            new O2SwitchDeployConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: true,
                accountLogicalId: 'acct',
                deploymentRootBase: 'relative/invalid',
                gestionGitRepositoryUrl: 'https://github.com/mkddanfakha/Gestion.git',
                defaultGitRef: '1.0.0',
                cpanelHost: '',
            ),
        );

        $result = $adapter->deployApplication($this->makeContext());

        $this->assertSame('o2switch_deploy_invalid_path', $result->code);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchDeployAdapter(
            new NullO2SwitchDeployGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->deployApplication($this->makeContext(['target_version' => '1.2.0']));

        Http::assertNothingSent();
        $this->assertSame('o2switch_deploy_protocol_pending', $result->code);
    }

    public function test_cpanel_gateway_creates_repository_when_absent(): void
    {
        Http::fake([
            '*2083/execute/Git/retrieve*' => Http::response(['result' => ['data' => []]], 200),
            '*2083/execute/Git/create*' => Http::response(['result' => ['status' => 1]], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelGitUapiO2SwitchDeployGateway;
        $context = $this->makeContext(['target_version' => '1.2.0']);
        $result = $gateway->deployApplication($context, $this->liveReadyConfiguration());

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['created'] ?? false);
        Http::assertSentCount(2);
    }

    public function test_cpanel_gateway_idempotent_when_same_reference_deployed(): void
    {
        Http::fake([
            '*2083/execute/Git/retrieve*' => Http::response([
                'result' => ['data' => ['branch' => '1.2.0']],
            ], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelGitUapiO2SwitchDeployGateway;
        $result = $gateway->deployApplication(
            $this->makeContext(['target_version' => '1.2.0']),
            $this->liveReadyConfiguration(),
        );

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
        Http::assertSentCount(1);
    }

    public function test_cpanel_gateway_updates_when_reference_differs(): void
    {
        Http::fake([
            '*2083/execute/Git/retrieve*' => Http::response([
                'result' => ['data' => ['branch' => '1.0.0']],
            ], 200),
            '*2083/execute/Git/update*' => Http::response(['result' => ['status' => 1]], 200),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelGitUapiO2SwitchDeployGateway;
        $result = $gateway->deployApplication(
            $this->makeContext(['target_version' => '1.2.0']),
            $this->liveReadyConfiguration(),
        );

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['updated'] ?? false);
        Http::assertSentCount(2);
    }

    public function test_cpanel_gateway_access_denied_without_real_network(): void
    {
        Http::fake([
            '*2083/execute/Git/retrieve*' => Http::response([], 403),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelGitUapiO2SwitchDeployGateway;
        $result = $gateway->deployApplication(
            $this->makeContext(['target_version' => '1.2.0']),
            $this->liveReadyConfiguration(),
        );

        $this->assertSame('o2switch_deploy_access_denied', $result->code);
    }

    public function test_fake_gateway_error_propagates_through_deploy_step(): void
    {
        $fake = new FakeO2SwitchDeployGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_deploy_gateway_error',
            'Erreur simulée.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $this->app->instance(O2SwitchDeployGateway::class, $fake);
        $this->app->instance(ApplicationDeployAdapter::class, new O2SwitchDeployAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(DeployProvisioningStep::class);

        $result = app(DeployProvisioningStep::class)->execute($this->makeContext(['target_version' => '1.2.0']));

        $this->assertSame('o2switch_deploy_gateway_error', $result->code);
        $this->assertCount(1, $fake->calls);
    }

    public function test_results_never_expose_api_token_or_ssh_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-deploy-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');
        config()->set('provisioning.secrets.o2switch_ssh_username', 'ssh-user-secret');

        $adapter = new O2SwitchDeployAdapter(new NullO2SwitchDeployGateway);
        $result = $adapter->deployApplication($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-deploy-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('ssh-user-secret', (string) $encoded);
    }

    private function dryRunConfiguration(): O2SwitchDeployConfiguration
    {
        return new O2SwitchDeployConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            gestionGitRepositoryUrl: 'https://github.com/mkddanfakha/Gestion.git',
            defaultGitRef: '',
            cpanelHost: '',
        );
    }

    private function liveReadyConfiguration(bool $enabled = true): O2SwitchDeployConfiguration
    {
        return new O2SwitchDeployConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: false,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            gestionGitRepositoryUrl: 'https://github.com/mkddanfakha/Gestion.git',
            defaultGitRef: '',
            cpanelHost: 'panel.example.test',
        );
    }

    /**
     * @param  array<string, mixed>  $runOverrides
     */
    private function makeContext(array $runOverrides = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Deploy Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Deploy Installation',
            'subdomain' => 'deploy-'.uniqid(),
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create(array_merge([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ], $runOverrides));

        $run->setRelation('installation', $installation);
        $installation->setRelation('client', $client);

        return ProvisioningContext::fromRun($run);
    }
}
