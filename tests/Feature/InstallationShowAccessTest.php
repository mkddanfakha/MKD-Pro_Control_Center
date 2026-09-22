<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationShowAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_includes_accessible_state_for_active_subscription(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Show')
                ->where('access.accessible', true)
                ->where('access.status', 'accessible'));
    }

    public function test_show_includes_accessible_state_for_grace_period_subscription(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.accessible', true)
                ->where('access.status', 'accessible'));
    }

    public function test_show_includes_suspended_access_state(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.accessible', false)
                ->where('access.status', 'suspended'));
    }

    public function test_show_includes_terminated_access_state(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.accessible', false)
                ->where('access.status', 'terminated'));
    }

    public function test_show_includes_no_subscription_access_state(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.accessible', false)
                ->where('access.status', 'no_subscription'));
    }

    /**
     * @param  array<string, mixed>  $subscriptionAttributes
     */
    private function makeInstallationWithSubscription(array $subscriptionAttributes = []): Installation
    {
        $installation = $this->makeInstallation();

        Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $subscriptionAttributes));

        return $installation;
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
