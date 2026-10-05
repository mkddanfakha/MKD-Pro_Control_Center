<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchCacheGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\NullO2SwitchCacheGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheArtisanCommandPolicy;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheConfiguration;
use App\Services\Provisioning\Steps\CacheProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchCacheGateway;
use Tests\TestCase;

class O2SwitchCacheAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.cache', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'deployment_root_base' => '',
            'cpanel_host' => '',
        ]);

        config()->set('provisioning.gestion.cache_warmup_artisan_steps', [
            ['php', 'artisan', 'config:cache'],
            ['php', 'artisan', 'view:cache'],
            ['php', 'artisan', 'event:cache'],
        ]);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(CacheWarmupAdapter::class);
        $this->assertInstanceOf(O2SwitchCacheAdapter::class, $adapter);

        $result = $adapter->warmCache($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_cache_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_cache_disabled',
            $adapter->warmCache($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            new O2SwitchCacheConfiguration(
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
            'o2switch_cache_not_configured',
            $adapter->warmCache($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_planned_commands(): void
    {
        Http::fake();

        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->warmCache($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertSame(['config:cache', 'view:cache', 'event:cache'], $result->outputSummary['planned_cache_commands'] ?? null);
        $this->assertContains('route:cache', $result->outputSummary['excluded_cache_commands'] ?? []);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
            'gestion_migrate_step_ready' => true,
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_cache_protocol_pending', $result->code);
    }

    public function test_control_center_deployment_root_is_forbidden(): void
    {
        $controlCenterRoot = rtrim(str_replace('\\', '/', base_path()), '/');

        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            new O2SwitchCacheConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: true,
                accountLogicalId: 'acct',
                deploymentRootBase: $controlCenterRoot,
                cpanelHost: '',
                forbiddenAbsolutePathPrefixes: [$controlCenterRoot],
            ),
        );

        $result = $adapter->warmCache($this->makeContext());

        $this->assertSame('o2switch_storage_deployment_path_invalid', $result->code);
    }

    public function test_installation_a_cannot_use_installation_b_deploy_path(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Cache Cross Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installationB = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation B',
            'subdomain' => 'cache-b-'.uniqid(),
            'status' => 'active',
        ]);

        $installationA = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation A',
            'subdomain' => 'cache-a-'.uniqid(),
            'status' => 'active',
        ]);

        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->warmCache($this->makeContextForInstallation($installationA, [
            'deploy_relative_path' => 'mkd_gestion/installation_'.$installationB->id,
        ]));

        $this->assertSame('o2switch_storage_deployment_path_invalid', $result->code);
    }

    public function test_live_mode_without_prerequisites_returns_not_ready(): void
    {
        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext());

        $this->assertSame('o2switch_cache_prerequisites_not_ready', $result->code);
    }

    public function test_live_mode_database_cache_without_migrate_confirmation(): void
    {
        $adapter = new O2SwitchCacheAdapter(
            new NullO2SwitchCacheGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
        ]));

        $this->assertSame('o2switch_cache_database_tables_unconfirmed', $result->code);
    }

    public function test_fake_gateway_config_cache_success(): void
    {
        $fake = new FakeO2SwitchCacheGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['operation_completed' => 'laravel_production_cache_warmup'],
        );

        $adapter = new O2SwitchCacheAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
            'gestion_migrate_step_ready' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame(['config:cache', 'view:cache', 'event:cache'], $fake->calls[0]['planned_commands']);
    }

    public function test_fake_gateway_idempotent_replay_already_cached(): void
    {
        $fake = new FakeO2SwitchCacheGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operation_completed' => 'laravel_production_cache_warmup',
                'idempotent_replay' => true,
            ],
            metadata: ['cache_state' => 'already_cached'],
        );

        $adapter = new O2SwitchCacheAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
            'gestion_migrate_step_ready' => true,
        ]));

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_fake_gateway_incompatible_route_cache_manual_intervention(): void
    {
        $fake = new FakeO2SwitchCacheGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_cache_route_cache_incompatible',
            'route:cache incompatible avec les routes Gestion.',
        );

        $adapter = new O2SwitchCacheAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
            'gestion_migrate_step_ready' => true,
        ]));

        $this->assertSame('o2switch_cache_route_cache_incompatible', $result->code);
    }

    public function test_fake_gateway_command_failure(): void
    {
        $fake = new FakeO2SwitchCacheGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_cache_view_cache_failed',
            'view:cache simulé en échec.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchCacheAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->warmCache($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
            'gestion_migrate_step_ready' => true,
        ]));

        $this->assertSame('o2switch_cache_view_cache_failed', $result->code);
    }

    public function test_fake_gateway_propagates_through_cache_step(): void
    {
        $fake = new FakeO2SwitchCacheGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_cache_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchCacheGateway::class, $fake);
        $this->app->instance(CacheWarmupAdapter::class, new O2SwitchCacheAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(CacheProvisioningStep::class);

        $result = app(CacheProvisioningStep::class)->execute($this->makeContext([], [
            'gestion_cache_warmup_ready' => true,
            'gestion_migrate_step_ready' => true,
        ]));

        $this->assertSame('o2switch_cache_manual', $result->code);
    }

    public function test_results_never_expose_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-cache-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchCacheAdapter(new NullO2SwitchCacheGateway, $this->dryRunConfiguration());
        $result = $adapter->warmCache($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-cache-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('APP_KEY', (string) $encoded);
    }

    public function test_production_plan_excludes_forbidden_commands(): void
    {
        $plan = O2SwitchCacheArtisanCommandPolicy::productionCacheWarmupSteps();
        $joined = json_encode($plan);

        foreach ([
            'route:cache',
            'optimize:clear',
            'optimize',
            'cache:clear',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $joined, $forbidden);
        }

        $this->assertTrue(O2SwitchCacheArtisanCommandPolicy::assertProductionPlan($plan));
    }

    public function test_tampered_config_with_forbidden_step_fails_policy(): void
    {
        config()->set('provisioning.gestion.cache_warmup_artisan_steps', [
            ['php', 'artisan', 'optimize:clear'],
        ]);

        $this->assertFalse(O2SwitchCacheArtisanCommandPolicy::assertProductionPlan(
            O2SwitchCacheArtisanCommandPolicy::productionCacheWarmupSteps(),
        ));
    }

    public function test_route_cache_is_explicitly_excluded_from_gestion_profile(): void
    {
        $this->assertContains('route:cache', config('provisioning.gestion.cache_excluded_artisan_commands'));
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     * @param  array<string, mixed>  $moreExternal
     */
    private function dryRunConfiguration(bool $enabled = true): O2SwitchCacheConfiguration
    {
        return new O2SwitchCacheConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );
    }

    private function liveReadyConfiguration(): O2SwitchCacheConfiguration
    {
        return new O2SwitchCacheConfiguration(
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
     * @param  array<string, mixed>  $moreExternal
     */
    private function makeContext(array $externalReferences = [], array $moreExternal = []): ProvisioningContext
    {
        $refs = array_merge($externalReferences, $moreExternal);

        $client = Client::query()->create([
            'company_name' => 'Cache Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Cache Installation',
            'subdomain' => 'cache-'.uniqid(),
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
