<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHealthGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Gestion\GestionHealthCheckCatalog;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\NullO2SwitchHealthGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthArtisanCommandPolicy;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthConfiguration;
use App\Services\Provisioning\Steps\HealthProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchHealthGateway;
use Tests\TestCase;

class O2SwitchHealthAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.health', [
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

        $adapter = app(ApplicationHealthAdapter::class);
        $this->assertInstanceOf(O2SwitchHealthAdapter::class, $adapter);

        $result = $adapter->checkHealth($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_health_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_health_disabled',
            $adapter->checkHealth($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            new O2SwitchHealthConfiguration(
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
            'o2switch_health_not_configured',
            $adapter->checkHealth($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_structured_check_plan(): void
    {
        Http::fake();

        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
        ]));

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertGreaterThanOrEqual(10, count($result->outputSummary['checks_planned'] ?? []));
    }

    public function test_health_check_order_is_deterministic(): void
    {
        $keys = GestionHealthCheckCatalog::checkKeysInOrder();

        $this->assertSame('application_http_up', $keys[0] ?? null);
        $this->assertContains('migration_status', $keys);
        $this->assertContains('admin_bootstrap_readiness', $keys);
    }

    public function test_dry_run_separates_health_and_readiness_scopes(): void
    {
        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
        ]));

        $health = $result->outputSummary['health_scope_checks'] ?? [];
        $readiness = $result->outputSummary['readiness_scope_checks'] ?? [];

        $this->assertContains('application_http_up', $health);
        $this->assertNotContains('admin_bootstrap_readiness', $health);
        $this->assertContains('admin_bootstrap_readiness', $readiness);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
            'gestion_health_step_ready' => true,
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_health_protocol_pending', $result->code);
    }

    public function test_live_mode_without_prerequisites_returns_not_ready(): void
    {
        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
        ]));

        $this->assertSame('o2switch_health_prerequisites_not_ready', $result->code);
    }

    public function test_live_mode_without_http_target_returns_url_pending(): void
    {
        $adapter = new O2SwitchHealthAdapter(
            new NullO2SwitchHealthGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        config()->set('provisioning.o2switch.environment.gestion_app_base_domain', '');

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_health_step_ready' => true,
        ]));

        $this->assertSame('o2switch_health_target_url_pending', $result->code);
    }

    public function test_fake_gateway_all_checks_success(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'overall_health' => 'healthy',
                'readiness_state' => 'ready',
                'checks_passed' => ['application_http_up', 'migration_status'],
            ],
        );

        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
            'gestion_health_step_ready' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('healthy', $result->outputSummary['overall_health'] ?? null);
    }

    public function test_fake_gateway_application_unreachable(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_health_application_unreachable',
            'Application inaccessible.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
            outputSummary: ['failed_check' => 'application_http_up'],
        );

        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $this->assertSame(
            'o2switch_health_application_unreachable',
            $adapter->checkHealth($this->makeContext([
                'gestion_application_public_base_url' => 'https://client-example.test',
                'gestion_health_step_ready' => true,
            ]))->code,
        );
    }

    public function test_fake_gateway_database_unavailable(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_health_database_unavailable',
            'Base indisponible.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $this->assertSame(
            'o2switch_health_database_unavailable',
            $adapter->checkHealth($this->makeContext([
                'gestion_application_public_base_url' => 'https://client-example.test',
                'gestion_health_step_ready' => true,
            ]))->code,
        );
    }

    public function test_fake_gateway_migrations_incomplete(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_health_migrations_incomplete',
            'Migrations en attente.',
        );

        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $this->assertSame(
            'o2switch_health_migrations_incomplete',
            $adapter->checkHealth($this->makeContext([
                'gestion_application_public_base_url' => 'https://client-example.test',
                'gestion_health_step_ready' => true,
            ]))->code,
        );
    }

    public function test_fake_gateway_build_manifest_missing(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_health_build_missing',
            'Manifest Vite absent.',
            retryable: false,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Definitive,
        );

        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $this->assertSame(
            'o2switch_health_build_missing',
            $adapter->checkHealth($this->makeContext([
                'gestion_application_public_base_url' => 'https://client-example.test',
                'gestion_health_step_ready' => true,
            ]))->code,
        );
    }

    public function test_fake_gateway_admin_absent_readiness(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_health_admin_missing',
            'Administrateur absent.',
            outputSummary: ['readiness_state' => 'not_ready'],
        );

        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
            'gestion_health_step_ready' => true,
        ]));

        $this->assertSame('not_ready', $result->outputSummary['readiness_state'] ?? null);
    }

    public function test_fake_gateway_idempotent_replay(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $adapter = new O2SwitchHealthAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $context = $this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
            'gestion_health_step_ready' => true,
        ]);

        $adapter->checkHealth($context);
        $second = $adapter->checkHealth($context);

        $this->assertTrue($second->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_provisioning_context_rejects_credential_in_external_references(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeContext([
            'credential_ref' => 'must-not-appear',
        ]);
    }

    public function test_results_never_expose_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-health-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchHealthAdapter(new NullO2SwitchHealthGateway, $this->dryRunConfiguration());
        $result = $adapter->checkHealth($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
        ]));

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-health-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
    }

    public function test_forbidden_artisan_commands_are_rejected(): void
    {
        config()->set('provisioning.gestion.migration_status_artisan_argv', [
            'php', 'artisan', 'migrate:fresh',
        ]);

        $this->assertFalse(O2SwitchHealthArtisanCommandPolicy::isAllowedStep(
            config('provisioning.gestion.migration_status_artisan_argv'),
        ));

        config()->set('provisioning.gestion.migration_status_artisan_argv', [
            'php', 'artisan', 'migrate:status', '--no-ansi',
        ]);
    }

    public function test_plan_excludes_destructive_commands_by_default(): void
    {
        $joined = json_encode(O2SwitchHealthArtisanCommandPolicy::allowedReadonlyArtisanSteps());

        foreach (['migrate:fresh', 'db:wipe', 'optimize:clear', 'cache:clear'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $joined);
        }
    }

    public function test_fake_gateway_propagates_through_health_step(): void
    {
        $fake = new FakeO2SwitchHealthGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_health_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchHealthGateway::class, $fake);
        $this->app->instance(ApplicationHealthAdapter::class, new O2SwitchHealthAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(HealthProvisioningStep::class);

        $result = app(HealthProvisioningStep::class)->execute($this->makeContext([
            'gestion_application_public_base_url' => 'https://client-example.test',
            'gestion_health_step_ready' => true,
        ]));

        $this->assertSame('o2switch_health_manual', $result->code);
    }

    public function test_url_resolver_uses_installation_domain(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Health URL Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Health URL Installation',
            'subdomain' => 'health-sub',
            'domain' => 'gestion-client.example.test',
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);
        $run->setRelation('installation', $installation);

        $context = new ProvisioningContext(
            provisioningRun: $run,
            installation: $installation,
            targetVersion: null,
            targetCommit: null,
            pipelineVersion: null,
        );

        $adapter = new O2SwitchHealthAdapter(new NullO2SwitchHealthGateway, $this->dryRunConfiguration());
        $result = $adapter->checkHealth($context);

        $this->assertTrue($result->outputSummary['application_public_base_url_resolved'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function dryRunConfiguration(bool $enabled = true): O2SwitchHealthConfiguration
    {
        return new O2SwitchHealthConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );
    }

    private function liveReadyConfiguration(): O2SwitchHealthConfiguration
    {
        return new O2SwitchHealthConfiguration(
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
            'company_name' => 'Health Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Health Installation',
            'subdomain' => 'health-'.uniqid(),
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
