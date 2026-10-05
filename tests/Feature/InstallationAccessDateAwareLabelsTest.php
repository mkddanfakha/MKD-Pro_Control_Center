<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationAccessDateAwareLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_show_vue_no_longer_exposes_access_detail_helper(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Clients/Show.vue'));

        $this->assertStringNotContainsString('function accessDetailLabel', $contents);
        $this->assertStringContainsString('subscriptionStatusLabel', $contents);
    }

    public function test_installation_show_exposes_expired_active_access_props(): void
    {
        $this->travelTo('2026-11-01 00:00:00');

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription.status', Subscription::STATUS_ACTIVE));
    }

    public function test_installation_show_exposes_expired_grace_period_access_props(): void
    {
        $this->travelTo('2026-11-08 00:00:00');

        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'current_period_end' => '2026-10-31 23:59:59',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription.status', Subscription::STATUS_GRACE_PERIOD));
    }

    public function test_installation_index_exposes_suspended_current_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.current_subscription.status', Subscription::STATUS_SUSPENDED));
    }

    public function test_client_show_exposes_no_subscription_access_props(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->has('subscriptions.data', 0)
                ->missing('installationOverviews'));
    }

    /**
     * @param  array<string, mixed>  $subscriptionAttributes
     */
    private function makeInstallationWithSubscription(array $subscriptionAttributes = []): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ]);

        Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $subscriptionAttributes));

        return $installation;
    }
}
