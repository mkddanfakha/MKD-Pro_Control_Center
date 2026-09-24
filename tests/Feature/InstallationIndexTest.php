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

    public function test_index_includes_installation_status_and_computed_access(): void
    {
        $user = User::factory()->create();
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
                ->where('installations.data.0.access.accessible', false)
                ->where('installations.data.0.access.status', 'suspended')
                ->where('installations.data.0.access.subscription_status', Subscription::STATUS_SUSPENDED)
                ->missing('installations.data.0.subscriptions'));
    }

    public function test_index_access_reflects_grace_period_subscription_status(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation(['status' => 'suspended']);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.status', 'suspended')
                ->where('installations.data.0.access.accessible', true)
                ->where('installations.data.0.access.status', 'accessible')
                ->where('installations.data.0.access.subscription_status', Subscription::STATUS_GRACE_PERIOD));
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
