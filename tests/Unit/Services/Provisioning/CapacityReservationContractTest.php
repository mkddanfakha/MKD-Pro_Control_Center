<?php

namespace Tests\Unit\Services\Provisioning;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\Local\LocalCapacityReservationAdapter;
use App\Services\Provisioning\Infrastructure\Local\LocalControlledInfrastructureState;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Support\Provisioning\CapacityReservationContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityReservationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_logical_output_summary_shape_and_no_secrets(): void
    {
        $summary = CapacityReservationContract::logicalSucceededOutputSummary(42);

        CapacityReservationContract::assertLogicalSucceededShape($summary);
        $this->assertSame('cc-logical-installation-42', $summary['reservation_id']);
        $this->assertSame('mkd_gestion/installation_42', $summary['deploy_relative_path']);
    }

    public function test_sanitize_rejects_forbidden_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CapacityReservationContract::sanitizePublicOutputSummary([
            'db_password' => 'x',
        ]);
    }

    public function test_unavailable_adapter_returns_manual_intervention_without_secrets(): void
    {
        $result = (new UnavailableCapacityReservationAdapter)->reserve($this->makeContext());

        $this->assertSame(
            InfrastructureAdapterResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
        $this->assertSame('capacity_reservation_unavailable', $result->code);

        $encoded = json_encode($result->outputSummary + $result->metadata);
        $this->assertStringNotContainsString('password', strtolower((string) $encoded));
    }

    public function test_local_adapter_is_idempotent_for_same_installation(): void
    {
        $state = new LocalControlledInfrastructureState;
        $adapter = new LocalCapacityReservationAdapter($state);
        $context = $this->makeContext();

        $first = $adapter->reserve($context);
        $second = $adapter->reserve($context);

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $first->outcome);
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $second->outcome);
        $this->assertTrue($second->outputSummary['idempotent_replay'] ?? false);
        $this->assertSame(
            $first->outputSummary['reservation_id'],
            $second->outputSummary['reservation_id'],
        );
        $this->assertTrue($state->isOperationApplied($context->installationId, 'capacity_reservation'));
    }

    public function test_local_adapter_assigns_distinct_reservation_per_installation(): void
    {
        $state = new LocalControlledInfrastructureState;
        $adapter = new LocalCapacityReservationAdapter($state);

        $a = $adapter->reserve($this->makeContext());
        $b = $adapter->reserve($this->makeContext());

        $this->assertNotSame(
            $a->outputSummary['reservation_id'],
            $b->outputSummary['reservation_id'],
        );
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Contract Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Install Contract',
            'subdomain' => 'sub-'.uniqid(),
            'domain' => 'client.example.test',
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        return ProvisioningContext::fromRun($run);
    }
}
