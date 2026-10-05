<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\Exceptions\Provisioning\ProvisioningRunCreationException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Support\Provisioning\CapacityReservationContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityReservationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_binds_unavailable_capacity_adapter_only(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(UnavailableCapacityReservationAdapter::class, $source);
        $this->assertStringNotContainsString('O2SwitchCapacity', $source);

        $this->assertInstanceOf(UnavailableCapacityReservationAdapter::class, app(CapacityReservationAdapter::class));
    }

    public function test_factory_prevents_concurrent_pending_runs_for_same_installation(): void
    {
        $installation = $this->makeInstallation();
        $factory = app(ProvisioningRunFactory::class);

        $factory->createRequest($installation);

        $this->expectException(ProvisioningRunCreationException::class);
        $factory->createRequest($installation->fresh());
    }

    public function test_retry_creates_new_run_while_prior_reserve_step_may_remain_succeeded(): void
    {
        $installation = $this->makeInstallation();
        $factory = app(ProvisioningRunFactory::class);

        $first = $factory->createRequest($installation);
        $first->steps()->where('step_key', CapacityReservationContract::STEP_KEY)->update([
            'status' => 'succeeded',
            'output_summary' => CapacityReservationContract::logicalSucceededOutputSummary($installation->id),
        ]);
        $first->update([
            'status' => ProvisioningRun::STATUS_FAILED,
            'finished_at' => now(),
        ]);

        $retry = $factory->createRequest($installation->fresh());

        $this->assertNotSame($first->id, $retry->id);
        $this->assertSame($first->id, $retry->retry_of_run_id);
        $this->assertSame(ProvisioningRun::STATUS_PENDING, $retry->status);
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Arch Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Arch Install',
            'subdomain' => 'arch-'.uniqid(),
            'domain' => 'client.example.test',
            'status' => 'active',
        ]);
    }
}
