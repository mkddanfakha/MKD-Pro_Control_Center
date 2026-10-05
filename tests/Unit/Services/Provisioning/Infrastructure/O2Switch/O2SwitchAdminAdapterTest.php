<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchAdminGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\NullO2SwitchAdminGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminArtisanCommandPolicy;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminConfiguration;
use App\Services\Provisioning\Steps\AdminProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchAdminGateway;
use Tests\TestCase;

class O2SwitchAdminAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.admin', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'deployment_root_base' => '',
            'cpanel_host' => '',
        ]);

        config()->set('provisioning.gestion.admin_bootstrap_artisan_steps', []);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(AdminBootstrapAdapter::class);
        $this->assertInstanceOf(O2SwitchAdminAdapter::class, $adapter);

        $result = $adapter->bootstrapAdmin($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_admin_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_admin_disabled',
            $adapter->bootstrapAdmin($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            new O2SwitchAdminConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                deploymentRootBase: '',
                cpanelHost: '',
                forbiddenAbsolutePathPrefixes: [],
            ),
        );

        $this->assertSame(
            'o2switch_admin_not_configured',
            $adapter->bootstrapAdmin($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_structured_plan(): void
    {
        Http::fake();

        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_name' => 'Admin Client',
        ]));

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertSame(0, $result->outputSummary['artisan_steps_planned_count'] ?? -1);
    }

    public function test_missing_admin_email_returns_identity_invalid(): void
    {
        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->dryRunConfiguration(),
        );

        $this->assertSame(
            'o2switch_admin_identity_invalid',
            $adapter->bootstrapAdmin($this->makeContext())->code,
        );
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_admin_protocol_pending', $result->code);
    }

    public function test_live_mode_without_prerequisites_returns_not_ready(): void
    {
        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        $this->assertSame('o2switch_admin_prerequisites_not_ready', $result->code);
    }

    public function test_live_mode_without_secure_delivery_channel_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
        ]));

        $this->assertSame('o2switch_admin_secure_delivery_required', $result->code);
    }

    public function test_fake_gateway_bootstrap_success(): void
    {
        $fake = new FakeO2SwitchAdminGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['operation_completed' => 'gestion_admin_bootstrap'],
        );

        $adapter = new O2SwitchAdminAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
    }

    public function test_fake_gateway_idempotent_replay_admin_already_present(): void
    {
        $fake = new FakeO2SwitchAdminGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operation_completed' => 'gestion_admin_bootstrap',
                'idempotent_replay' => true,
            ],
            metadata: ['admin_state' => 'already_present'],
        );

        $adapter = new O2SwitchAdminAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_fake_gateway_admin_conflict(): void
    {
        $fake = new FakeO2SwitchAdminGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_admin_conflict',
            'Administrateur existant incompatible.',
        );

        $adapter = new O2SwitchAdminAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        $this->assertSame('o2switch_admin_conflict', $result->code);
    }

    public function test_fake_gateway_creation_failure(): void
    {
        $fake = new FakeO2SwitchAdminGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_admin_create_failed',
            'Échec simulé.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchAdminAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        $this->assertSame('o2switch_admin_create_failed', $result->code);
    }

    public function test_provisioning_context_rejects_password_in_external_references(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'password' => 'must-not-appear',
        ]);
    }

    public function test_results_never_expose_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-admin-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchAdminAdapter(new NullO2SwitchAdminGateway, $this->dryRunConfiguration());
        $result = $adapter->bootstrapAdmin($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
        ]));

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-admin-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('"password":', (string) $encoded);
    }

    public function test_forbidden_artisan_commands_are_rejected_from_plan(): void
    {
        config()->set('provisioning.gestion.admin_bootstrap_artisan_steps', [
            ['php', 'artisan', 'migrate:fresh'],
        ]);

        $this->assertFalse(O2SwitchAdminArtisanCommandPolicy::assertPlanArtisanSteps(
            O2SwitchAdminArtisanCommandPolicy::allowedArtisanSteps(),
        ));
    }

    public function test_installation_a_cannot_use_installation_b_deploy_path(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Admin Cross Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installationB = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation B',
            'subdomain' => 'admin-b-'.uniqid(),
            'status' => 'active',
        ]);

        $installationA = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation A',
            'subdomain' => 'admin-a-'.uniqid(),
            'status' => 'active',
        ]);

        $adapter = new O2SwitchAdminAdapter(
            new NullO2SwitchAdminGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->bootstrapAdmin($this->makeContextForInstallation($installationA, [
            'gestion_admin_bootstrap_email' => 'admin@a.test',
            'deploy_relative_path' => 'mkd_gestion/installation_'.$installationB->id,
        ]));

        $this->assertSame('o2switch_storage_deployment_path_invalid', $result->code);
    }

    public function test_fake_gateway_propagates_through_admin_step(): void
    {
        $fake = new FakeO2SwitchAdminGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_admin_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchAdminGateway::class, $fake);
        $this->app->instance(AdminBootstrapAdapter::class, new O2SwitchAdminAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(AdminProvisioningStep::class);

        $result = app(AdminProvisioningStep::class)->execute($this->makeContext([
            'gestion_admin_bootstrap_email' => 'admin@client-example.test',
            'gestion_admin_bootstrap_ready' => true,
            'gestion_admin_secure_delivery_ready' => true,
        ]));

        $this->assertSame('o2switch_admin_manual', $result->code);
    }

    public function test_plan_excludes_destructive_commands_by_default(): void
    {
        $steps = O2SwitchAdminArtisanCommandPolicy::allowedArtisanSteps();
        $joined = json_encode($steps);

        foreach (['migrate:fresh', 'db:wipe', 'optimize:clear', 'user:delete'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $joined);
        }
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function dryRunConfiguration(bool $enabled = true): O2SwitchAdminConfiguration
    {
        return new O2SwitchAdminConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );
    }

    private function liveReadyConfiguration(): O2SwitchAdminConfiguration
    {
        return new O2SwitchAdminConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: 'panel.example.test',
            forbiddenAbsolutePathPrefixes: [],
        );
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function makeContext(array $externalReferences = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Admin Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Admin Installation',
            'subdomain' => 'admin-'.uniqid(),
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
