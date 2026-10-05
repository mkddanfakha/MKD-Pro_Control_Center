<?php

namespace Tests\Unit\Services\Provisioning\Steps;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProductionProvisioningStepCatalog;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\Provisioning\Steps\ValidateProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\MocksProvisioningExecutionReadiness;
use Tests\TestCase;

class ProvisioningStepContractTest extends TestCase
{
    use MocksProvisioningExecutionReadiness;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_production_step_implements_contract_with_canonical_key_and_order(): void
    {
        foreach (ProductionProvisioningStepCatalog::productionSteps() as $step) {
            $this->assertInstanceOf(ProvisioningStep::class, $step);
            $this->assertContains($step->stepKey(), ProvisioningRunStep::CANONICAL_STEP_KEYS);

            $expectedOrder = array_search($step->stepKey(), ProvisioningRunStep::CANONICAL_STEP_KEYS, true) + 1;
            $this->assertSame($expectedOrder, $step->order(), $step->stepKey());
        }
    }

    public function test_production_catalog_registers_eighteen_unique_deterministic_steps(): void
    {
        $registry = new ProvisioningStepRegistry;
        ProductionProvisioningStepCatalog::registerProductionSteps($registry);

        $this->assertSame(18, $registry->stepCount());
        $this->assertFalse($registry->isEmpty());

        $keys = $registry->orderedStepKeys();
        $registry->assertRegistryIntegrity($keys);

        $executableKeys = array_map(
            fn (ProvisioningStep $step) => $step->stepKey(),
            $registry->orderedExecutableSteps(),
        );

        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $executableKeys);
    }

    public function test_container_registry_has_eighteen_production_steps(): void
    {
        $registry = app(ProvisioningStepRegistry::class);

        $this->assertCount(18, $registry->orderedExecutableSteps());
    }

    public function test_production_step_execute_is_deterministic_without_external_calls(): void
    {
        $this->mockProvisioningExecutionReadinessReady();

        $context = $this->makeContext();

        foreach (ProductionProvisioningStepCatalog::productionSteps() as $step) {
            $first = $step->execute($context);
            $second = $step->execute($context);

            $this->assertSame($first->outcome, $second->outcome, $step->stepKey());
            $this->assertSame($first->code, $second->code, $step->stepKey());

            if ($step instanceof ValidateProvisioningStep) {
                $this->assertSame(ProvisioningStepResult::OUTCOME_SUCCEEDED, $first->outcome);
            } else {
                $this->assertSame(
                    ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
                    $first->outcome,
                );
            }

            $encoded = json_encode($first->metadata ?? []);
            $this->assertStringNotContainsString('password', strtolower((string) $encoded));
            $this->assertStringNotContainsString('api_key', strtolower((string) $encoded));
        }
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Step Test Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Step Installation',
            'subdomain' => 'steps-'.uniqid(),
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
