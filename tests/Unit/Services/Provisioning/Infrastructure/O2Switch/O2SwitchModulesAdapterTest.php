<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\Contracts\Provisioning\Infrastructure\InstallationModulesAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchModulesGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\NullO2SwitchModulesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesArtisanCommandPolicy;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesConfiguration;
use App\Services\Provisioning\Steps\ModulesProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchModulesGateway;
use Tests\TestCase;

class O2SwitchModulesAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.modules', [
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

        $adapter = app(InstallationModulesAdapter::class);
        $this->assertInstanceOf(O2SwitchModulesAdapter::class, $adapter);

        $result = $adapter->configureModules($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_modules_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_modules_disabled',
            $adapter->configureModules($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            new O2SwitchModulesConfiguration(
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
            'o2switch_modules_not_configured',
            $adapter->configureModules($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_empty_plan_when_no_selection(): void
    {
        Http::fake();

        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureModules($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->outputSummary['catalog_decision_required'] ?? false);
    }

    public function test_dry_run_succeeds_with_structured_plan_for_rbac_module(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['rbac_permission_definitions'],
            'gestion_migrate_step_ready' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertContains('rbac:sync-permission-catalog', $result->outputSummary['planned_artisan_commands'] ?? []);
    }

    public function test_unknown_module_returns_unknown_code(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(),
        );

        $this->assertSame(
            'o2switch_modules_unknown_module',
            $adapter->configureModules($this->makeContext([
                'gestion_modules_requested' => ['does_not_exist_in_gestion'],
            ]))->code,
        );
    }

    public function test_notification_center_is_noop_in_dry_run(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame(['notification_center'], $result->outputSummary['noop_module_ids'] ?? null);
    }

    public function test_missing_dependency_returns_dependency_missing(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(),
        );

        $this->assertSame(
            'o2switch_modules_dependency_missing',
            $adapter->configureModules($this->makeContext([
                'gestion_modules_requested' => ['rbac_permission_definitions'],
            ]))->code,
        );
    }

    public function test_invalid_selection_type_returns_selection_invalid(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->dryRunConfiguration(),
        );

        $this->assertSame(
            'o2switch_modules_selection_invalid',
            $adapter->configureModules($this->makeContext([
                'gestion_modules_requested' => 'not-an-array',
            ]))->code,
        );
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
            'gestion_modules_step_ready' => true,
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_modules_protocol_pending', $result->code);
    }

    public function test_live_mode_without_prerequisites_returns_not_ready(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
        ]));

        $this->assertSame('o2switch_modules_prerequisites_not_ready', $result->code);
    }

    public function test_live_mode_empty_selection_returns_selection_pending(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_step_ready' => true,
        ]));

        $this->assertSame('o2switch_modules_selection_pending', $result->code);
    }

    public function test_live_rbac_module_without_artisan_allowlist_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchModulesAdapter(
            new NullO2SwitchModulesGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['rbac_permission_definitions'],
            'gestion_migrate_step_ready' => true,
            'gestion_modules_step_ready' => true,
        ]));

        $this->assertSame('o2switch_modules_manual_intervention_required', $result->code);
    }

    public function test_fake_gateway_success(): void
    {
        $fake = new FakeO2SwitchModulesGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['operation_completed' => 'gestion_installation_modules'],
        );

        $adapter = new O2SwitchModulesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
            'gestion_modules_step_ready' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
    }

    public function test_fake_gateway_idempotent_replay_on_same_fingerprint(): void
    {
        $fake = new FakeO2SwitchModulesGateway;

        $adapter = new O2SwitchModulesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $context = $this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
            'gestion_modules_step_ready' => true,
        ]);

        $adapter->configureModules($context);
        $second = $adapter->configureModules($context);

        $this->assertTrue($second->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_fake_gateway_partial_failure_propagates(): void
    {
        $fake = new FakeO2SwitchModulesGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_modules_partial_failure',
            'Échec simulé sur un module.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchModulesAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
            'gestion_modules_step_ready' => true,
        ]));

        $this->assertSame('o2switch_modules_partial_failure', $result->code);
    }

    public function test_provisioning_context_rejects_token_in_external_references(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeContext([
            'api_token' => 'must-not-appear',
        ]);
    }

    public function test_results_never_expose_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-modules-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchModulesAdapter(new NullO2SwitchModulesGateway, $this->dryRunConfiguration());
        $result = $adapter->configureModules($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
        ]));

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-modules-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
    }

    public function test_forbidden_artisan_commands_are_rejected_from_plan(): void
    {
        config()->set('provisioning.gestion.modules_provisioning_artisan_steps', [
            ['php', 'artisan', 'migrate:fresh'],
        ]);

        $this->assertFalse(O2SwitchModulesArtisanCommandPolicy::assertPlanArtisanSteps(
            O2SwitchModulesArtisanCommandPolicy::allowedArtisanSteps(),
        ));
    }

    public function test_fake_gateway_propagates_through_modules_step(): void
    {
        $fake = new FakeO2SwitchModulesGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_modules_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchModulesGateway::class, $fake);
        $this->app->instance(InstallationModulesAdapter::class, new O2SwitchModulesAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(ModulesProvisioningStep::class);

        $result = app(ModulesProvisioningStep::class)->execute($this->makeContext([
            'gestion_modules_requested' => ['notification_center'],
            'gestion_modules_step_ready' => true,
        ]));

        $this->assertSame('o2switch_modules_manual', $result->code);
    }

    public function test_plan_excludes_destructive_commands_by_default(): void
    {
        $steps = array_merge(
            O2SwitchModulesArtisanCommandPolicy::allowedArtisanSteps(),
            O2SwitchModulesArtisanCommandPolicy::statusArtisanStep(),
            O2SwitchModulesArtisanCommandPolicy::catalogDryRunArtisanStep(),
        );
        $joined = json_encode($steps);

        foreach (['migrate:fresh', 'db:wipe', 'optimize:clear', 'cache:clear'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $joined);
        }
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function dryRunConfiguration(bool $enabled = true): O2SwitchModulesConfiguration
    {
        return new O2SwitchModulesConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );
    }

    private function liveReadyConfiguration(): O2SwitchModulesConfiguration
    {
        return new O2SwitchModulesConfiguration(
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
            'company_name' => 'Modules Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Modules Installation',
            'subdomain' => 'modules-'.uniqid(),
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
