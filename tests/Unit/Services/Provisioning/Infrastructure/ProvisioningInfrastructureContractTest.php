<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsAdapter;
use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\O2SwitchHostingAdapter;
use App\Contracts\Provisioning\Infrastructure\InstallationModulesAdapter;
use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\Infrastructure\InfrastructureAdapterResultMapper;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\Provisioning\Steps\ReserveProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Provisioning\Infrastructure\FakeCapacityReservationAdapter;
use Tests\TestCase;

class ProvisioningInfrastructureContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    private function infrastructureContractInterfaces(): array
    {
        return [
            CapacityReservationAdapter::class,
            DnsRecordAdapter::class,
            HostingSpaceAdapter::class,
            ClientDatabaseAdapter::class,
            ApplicationDeployAdapter::class,
            EnvironmentConfigurationAdapter::class,
            DependencyInstallationAdapter::class,
            ApplicationBuildAdapter::class,
            DatabaseMigrationAdapter::class,
            StorageSetupAdapter::class,
            CacheWarmupAdapter::class,
            AdminBootstrapAdapter::class,
            InstallationModulesAdapter::class,
            ApplicationHealthAdapter::class,
        ];
    }

    public function test_all_infrastructure_contracts_resolve_with_default_non_operational_bindings(): void
    {
        foreach ($this->infrastructureContractInterfaces() as $interface) {
            $adapter = app($interface);
            $this->assertNotNull($adapter);

            if ($interface === HostingSpaceAdapter::class) {
                $this->assertInstanceOf(O2SwitchHostingAdapter::class, $adapter);
            } elseif ($interface === DnsRecordAdapter::class) {
                $this->assertInstanceOf(CloudflareDnsAdapter::class, $adapter);
            } elseif ($interface === ClientDatabaseAdapter::class) {
                $this->assertInstanceOf(O2SwitchDatabaseAdapter::class, $adapter);
            } elseif ($interface === ApplicationDeployAdapter::class) {
                $this->assertInstanceOf(O2SwitchDeployAdapter::class, $adapter);
            } elseif ($interface === EnvironmentConfigurationAdapter::class) {
                $this->assertInstanceOf(O2SwitchEnvironmentAdapter::class, $adapter);
            } elseif ($interface === DependencyInstallationAdapter::class) {
                $this->assertInstanceOf(O2SwitchDependenciesAdapter::class, $adapter);
            } elseif ($interface === ApplicationBuildAdapter::class) {
                $this->assertInstanceOf(O2SwitchBuildAdapter::class, $adapter);
            } elseif ($interface === DatabaseMigrationAdapter::class) {
                $this->assertInstanceOf(O2SwitchMigrateAdapter::class, $adapter);
            } elseif ($interface === StorageSetupAdapter::class) {
                $this->assertInstanceOf(O2SwitchStorageAdapter::class, $adapter);
            } elseif ($interface === CacheWarmupAdapter::class) {
                $this->assertInstanceOf(O2SwitchCacheAdapter::class, $adapter);
            } elseif ($interface === AdminBootstrapAdapter::class) {
                $this->assertInstanceOf(O2SwitchAdminAdapter::class, $adapter);
            } elseif ($interface === InstallationModulesAdapter::class) {
                $this->assertInstanceOf(O2SwitchModulesAdapter::class, $adapter);
            } elseif ($interface === ApplicationHealthAdapter::class) {
                $this->assertInstanceOf(O2SwitchHealthAdapter::class, $adapter);
            } else {
                $this->assertStringContainsString('Unavailable', get_class($adapter));
            }
        }
    }

    public function test_unavailable_capacity_adapter_returns_manual_intervention_without_network(): void
    {
        $adapter = new UnavailableCapacityReservationAdapter;
        $context = $this->makeContext();

        $result = $adapter->reserve($context);

        $this->assertSame(
            InfrastructureAdapterResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
        $this->assertSame('capacity_reservation_unavailable', $result->code);

        $stepResult = InfrastructureAdapterResultMapper::toStepResult(
            ProvisioningRunStep::STEP_RESERVE,
            $result,
        );

        $this->assertSame(
            ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $stepResult->outcome,
        );
    }

    public function test_failed_adapter_result_maps_to_step_failure(): void
    {
        $adapterResult = InfrastructureAdapterResult::failed(
            'dns_propagation_timeout',
            'Propagation DNS incomplète.',
            retryable: true,
            category: ProvisioningErrorCategory::Retryable,
        );

        $stepResult = InfrastructureAdapterResultMapper::toStepResult(
            ProvisioningRunStep::STEP_DNS,
            $adapterResult,
        );

        $this->assertSame(ProvisioningStepResult::OUTCOME_FAILED, $stepResult->outcome);
        $this->assertSame('dns_propagation_timeout', $stepResult->code);
        $this->assertTrue($stepResult->retryable);
    }

    public function test_adapter_results_reject_forbidden_secret_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InfrastructureAdapterResult::succeeded(metadata: ['api_key' => 'hidden']);
    }

    public function test_reserve_step_delegates_to_injected_fake_adapter(): void
    {
        $fake = new FakeCapacityReservationAdapter;
        $fake->nextResult = InfrastructureAdapterResult::succeeded(
            outputSummary: ['reservation_ref' => 'fake-slot-1'],
        );

        $step = new ReserveProvisioningStep($fake);
        $context = $this->makeContext();

        $result = $step->execute($context);

        $this->assertSame(ProvisioningStepResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertCount(1, $fake->invocations);
        $this->assertSame($context->installationId, $fake->invocations[0]['installation_id']);

        $encoded = json_encode($result->metadata ?? []);
        $this->assertStringNotContainsString('password', strtolower((string) $encoded));
    }

    public function test_production_pipeline_stops_on_reserve_with_unavailable_adapters(): void
    {
        $registry = app(ProvisioningStepRegistry::class);
        $pipeline = new ProvisioningPipeline($registry);
        $context = $this->makeContext();

        $result = $pipeline->run($context);

        $this->assertSame(
            ProvisioningPipelineResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
        $this->assertSame(ProvisioningRunStep::STEP_RESERVE, $result->stoppedAtStepKey);
    }

    public function test_fake_adapter_does_not_perform_http_calls(): void
    {
        $fake = new FakeCapacityReservationAdapter;
        $context = $this->makeContext();

        $fake->reserve($context);

        $this->assertCount(1, $fake->invocations);
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Infra Contract Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Infra Installation',
            'subdomain' => 'infra-'.uniqid(),
            'status' => 'active',
        ]);

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
