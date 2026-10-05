<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchStorageGateway;
use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\NullO2SwitchStorageGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageConfiguration;
use App\Services\Provisioning\Steps\StorageProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeO2SwitchStorageGateway;
use Tests\TestCase;

class O2SwitchStorageAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.storage', [
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

        $adapter = app(StorageSetupAdapter::class);
        $this->assertInstanceOf(O2SwitchStorageAdapter::class, $adapter);

        $result = $adapter->configureStorage($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_storage_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->dryRunConfiguration(enabled: false),
        );

        $this->assertSame(
            'o2switch_storage_disabled',
            $adapter->configureStorage($this->makeContext())->code,
        );
    }

    public function test_enabled_without_configuration_is_not_configured(): void
    {
        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            new O2SwitchStorageConfiguration(
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
            'o2switch_storage_not_configured',
            $adapter->configureStorage($this->makeContext())->code,
        );
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureStorage($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertTrue($result->outputSummary['storage_link_planned'] ?? false);
        $this->assertSame('local', $result->metadata['default_filesystem_disk'] ?? null);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        Http::fake();

        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        Http::assertNothingSent();
        $this->assertSame('o2switch_storage_protocol_pending', $result->code);
    }

    public function test_control_center_absolute_path_prefix_is_forbidden(): void
    {
        $controlCenterRoot = rtrim(str_replace('\\', '/', base_path()), '/');

        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            new O2SwitchStorageConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: true,
                accountLogicalId: 'acct',
                deploymentRootBase: $controlCenterRoot,
                cpanelHost: '',
                forbiddenAbsolutePathPrefixes: [$controlCenterRoot],
            ),
        );

        $result = $adapter->configureStorage($this->makeContext());

        $this->assertSame('o2switch_storage_deployment_path_invalid', $result->code);
    }

    public function test_installation_a_cannot_use_installation_b_deploy_relative_path(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Storage Cross Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installationB = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation B',
            'subdomain' => 'storage-b-'.uniqid(),
            'status' => 'active',
        ]);

        $installationA = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation A',
            'subdomain' => 'storage-a-'.uniqid(),
            'status' => 'active',
        ]);

        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureStorage($this->makeContextForInstallation($installationA, [
            'deploy_relative_path' => 'mkd_gestion/installation_'.$installationB->id,
        ]));

        $this->assertSame('o2switch_storage_deployment_path_invalid', $result->code);
    }

    public function test_external_storage_target_installation_id_must_match_context(): void
    {
        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureStorage($this->makeContext([], [
            'storage_target_installation_id' => 424242,
        ]));

        $this->assertSame('o2switch_storage_deployment_path_invalid', $result->code);
    }

    public function test_live_mode_without_deploy_prerequisites_returns_not_ready(): void
    {
        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->liveReadyConfiguration(),
        );

        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext());

        $this->assertSame('o2switch_storage_deploy_not_ready', $result->code);
    }

    public function test_fake_gateway_directory_and_symlink_success(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['operation_completed' => 'storage_directories_and_storage_link'],
            metadata: ['storage_state' => 'configured'],
        );

        $adapter = new O2SwitchStorageAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->calls);
        $this->assertTrue($fake->calls[0]['requires_storage_link']);
    }

    public function test_fake_gateway_idempotent_replay_already_configured(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'operation_completed' => 'storage_directories_and_storage_link',
                'idempotent_replay' => true,
            ],
            metadata: ['storage_state' => 'already_configured'],
        );

        $adapter = new O2SwitchStorageAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
    }

    public function test_fake_gateway_incompatible_symlink_requires_manual_intervention(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_storage_symlink_incompatible',
            'Symlink public/storage incorrect.',
            metadata: ['storage_state' => 'symlink_incompatible'],
        );

        $adapter = new O2SwitchStorageAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertSame('o2switch_storage_symlink_incompatible', $result->code);
    }

    public function test_fake_gateway_real_path_instead_of_symlink_requires_manual_intervention(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_storage_symlink_blocked_by_directory',
            'public/storage est un répertoire réel.',
            metadata: ['storage_state' => 'link_path_is_directory'],
        );

        $adapter = new O2SwitchStorageAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertSame('o2switch_storage_symlink_blocked_by_directory', $result->code);
    }

    public function test_fake_gateway_permission_failure(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_storage_permission_denied',
            'Permissions insuffisantes sur storage/.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $adapter = new O2SwitchStorageAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertSame('o2switch_storage_permission_denied', $result->code);
    }

    public function test_fake_gateway_filesystem_failure(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'o2switch_storage_filesystem_error',
            'Erreur filesystem simulée.',
            retryable: false,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Definitive,
        );

        $adapter = new O2SwitchStorageAdapter($fake, $this->liveReadyConfiguration());
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $result = $adapter->configureStorage($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertSame('o2switch_storage_filesystem_error', $result->code);
    }

    public function test_fake_gateway_propagates_through_storage_step(): void
    {
        $fake = new FakeO2SwitchStorageGateway;
        $fake->nextResult = InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_storage_manual',
            'Intervention simulée.',
        );

        $this->app->instance(O2SwitchStorageGateway::class, $fake);
        $this->app->instance(StorageSetupAdapter::class, new O2SwitchStorageAdapter(
            $fake,
            $this->liveReadyConfiguration(),
        ));
        config()->set('provisioning.secrets.o2switch_api_token', 'fake-token-for-test');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        $this->app->forgetInstance(StorageProvisioningStep::class);

        $result = app(StorageProvisioningStep::class)->execute($this->makeContext([], [
            'gestion_deploy_filesystem_ready' => true,
        ]));

        $this->assertSame('o2switch_storage_manual', $result->code);
    }

    public function test_results_never_expose_secrets(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-storage-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $adapter = new O2SwitchStorageAdapter(new NullO2SwitchStorageGateway, $this->dryRunConfiguration());
        $result = $adapter->configureStorage($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-storage-token-value', (string) $encoded);
        $this->assertStringNotContainsString('cpanel-user-secret', (string) $encoded);
        $this->assertStringNotContainsString('AWS_SECRET', (string) $encoded);
    }

    public function test_storage_link_required_for_gestion_profile(): void
    {
        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureStorage($this->makeContext());

        $this->assertTrue(config('provisioning.gestion.storage_requires_storage_link'));
        $this->assertSame('storage_directories_and_storage_link', $result->outputSummary['operation_planned'] ?? null);
    }

    public function test_storage_plan_when_link_not_required(): void
    {
        config()->set('provisioning.gestion.storage_requires_storage_link', false);

        $adapter = new O2SwitchStorageAdapter(
            new NullO2SwitchStorageGateway,
            $this->dryRunConfiguration(),
        );

        $result = $adapter->configureStorage($this->makeContext());

        $this->assertSame('storage_directories_only', $result->outputSummary['operation_planned'] ?? null);
        $this->assertFalse($result->outputSummary['storage_link_planned'] ?? true);
    }

    public function test_plan_excludes_optimize_and_cache_commands(): void
    {
        $this->assertFalse(\App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageArtisanCommandPolicy::containsForbiddenCacheOrOptimizeCommand([
            'php', 'artisan', 'storage:link',
        ]));
        $this->assertTrue(\App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageArtisanCommandPolicy::containsForbiddenCacheOrOptimizeCommand([
            'php', 'artisan', 'optimize',
        ]));
    }

    /**
     * @param  array<string, mixed>  $externalReferences
     */
    private function dryRunConfiguration(bool $enabled = true): O2SwitchStorageConfiguration
    {
        return new O2SwitchStorageConfiguration(
            provider: 'o2switch',
            enabled: $enabled,
            dryRun: true,
            accountLogicalId: 'o2switch-account-ref',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenAbsolutePathPrefixes: [],
        );
    }

    private function liveReadyConfiguration(): O2SwitchStorageConfiguration
    {
        return new O2SwitchStorageConfiguration(
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
            'company_name' => 'Storage Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Storage Installation',
            'subdomain' => 'storage-'.uniqid(),
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
