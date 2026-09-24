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
                ->where('access.status', 'accessible')
                ->where('access.subscription_status', Subscription::STATUS_ACTIVE)
                ->has('lastSubscription')
                ->where('lastSubscription.status', Subscription::STATUS_ACTIVE));
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
                ->where('access.status', 'accessible')
                ->where('access.subscription_status', Subscription::STATUS_GRACE_PERIOD)
                ->where('lastSubscription.status', Subscription::STATUS_GRACE_PERIOD));
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
                ->where('access.status', 'no_subscription')
                ->where('access.subscription_status', null)
                ->where('lastSubscription', null));
    }

    public function test_show_preserves_installation_status_when_subscription_is_active(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation(['status' => 'suspended']);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installation.status', 'suspended')
                ->where('access.accessible', true)
                ->where('lastSubscription.status', Subscription::STATUS_ACTIVE));
    }

    public function test_show_last_subscription_includes_expected_fields(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'EUR',
            'notes' => 'Note interne show',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('installation.subscriptions')
                ->has('lastSubscription', fn (Assert $sub) => $sub
                    ->has('id')
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->where('amount', 9900)
                    ->where('currency', 'EUR')
                    ->has('starts_at')
                    ->has('current_period_start')
                    ->has('current_period_end')
                    ->has('grace_period_ends_at')
                    ->has('suspended_at')
                    ->has('terminated_at')
                    ->missing('notes')
                    ->missing('password')
                    ->missing('token')));
    }

    public function test_show_does_not_expose_subscription_history_on_installation(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 10000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'notes' => 'Ancien abonnement',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'notes' => 'Dernier abonnement',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('installation.subscriptions')
                ->missing('lastSubscription.notes')
                ->where('lastSubscription.status', Subscription::STATUS_ACTIVE));
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
