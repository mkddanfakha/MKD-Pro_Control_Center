<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_includes_installation_status_and_current_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['status' => 'active']);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_SUSPENDED,
            'notes' => 'Note interne index',
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Index')
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $installation->id)
                ->where('installations.data.0.status', 'active')
                ->where('installations.data.0.current_subscription.status', Subscription::STATUS_SUSPENDED)
                ->missing('installations.data.0.subscriptions')
                ->missing('installations.data.0.database_name'));
    }

    public function test_index_exposes_grace_period_as_current_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['status' => 'suspended']);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-12-31 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.status', 'suspended')
                ->where('installations.data.0.current_subscription.status', Subscription::STATUS_GRACE_PERIOD));
    }

    public function test_index_reports_null_current_subscription_for_terminated_only_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.id', $installation->id)
                ->where('installations.data.0.current_subscription', null));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstallation(array $attributes = []): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ], $attributes));
    }
}
