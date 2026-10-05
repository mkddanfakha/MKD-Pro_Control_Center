<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientShowEcosystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_without_installations(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Client sans installation',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 0)
                ->where('statistics.installations.total', 0));
    }

    public function test_show_with_installation_without_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $installation->id)
                ->has('subscriptions.data', 0));
    }

    public function test_show_with_active_subscription_lists_subscription_row(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $subscription = $this->makeSubscription($installation, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscriptions.data.0.id', $subscription->id)
                ->where('statistics.subscriptions.active', 1)
                ->has('payments.data', 1));
    }

    public function test_show_separates_multiple_installations(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installationA = $this->makeInstallation($client, ['name' => 'Installation A', 'subdomain' => 'a-'.uniqid()]);
        $installationB = $this->makeInstallation($client, ['name' => 'Installation B', 'subdomain' => 'b-'.uniqid()]);
        $this->makeSubscription($installationA);
        $this->makeSubscription($installationB);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 2)
                ->where('statistics.installations.total', 2));
    }

    public function test_show_does_not_trigger_n_plus_one_for_multiple_installations(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        for ($index = 0; $index < 3; $index++) {
            $installation = $this->makeInstallation($client, [
                'subdomain' => 'nplus-'.$index.'-'.uniqid(),
            ]);
            $subscription = $this->makeSubscription($installation);
            Payment::query()->create([
                'subscription_id' => $subscription->id,
                'amount' => 45000,
                'currency' => 'XOF',
                'status' => Payment::STATUS_PAID,
                'paid_at' => '2026-10-15 12:00:00',
                'monthly_unit_amount' => 15000,
                'credit_months_purchased' => 3,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('clients.show', $client))->assertOk();

        $subscriptionQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), ' from `subscriptions`'))
            ->count();

        $this->assertLessThanOrEqual(4, $subscriptionQueries);
    }

    private function makeClient(): Client
    {
        return Client::query()->create([
            'company_name' => 'Client écosystème',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstallation(Client $client, array $attributes = []): Installation
    {
        return Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Installation MKD-Pro',
            'subdomain' => 'eco-'.uniqid(),
            'status' => 'active',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(Installation $installation, array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }
}
