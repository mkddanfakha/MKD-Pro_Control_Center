<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
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
        $user = User::factory()->create();
        $client = Client::query()->create([
            'company_name' => 'Client sans installation',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clients/Show')
                ->has('installationOverviews', 0)
                ->where('ecosystemSummary.payments_count', 0)
                ->where('ecosystemSummary.total_available_credit_months', 0));
    }

    public function test_show_with_installation_without_subscription(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installationOverviews', 1)
                ->where('installationOverviews.0.installation.id', $installation->id)
                ->where('installationOverviews.0.access.status', 'no_subscription')
                ->where('installationOverviews.0.subscription', null)
                ->where('installationOverviews.0.credit', null));
    }

    public function test_show_with_active_subscription_and_credit(): void
    {
        $user = User::factory()->create();
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
                ->where('installationOverviews.0.access.accessible', true)
                ->where('installationOverviews.0.access.subscription_status', Subscription::STATUS_ACTIVE)
                ->where('installationOverviews.0.subscription.id', $subscription->id)
                ->where('installationOverviews.0.subscription.is_current_non_terminated', true)
                ->where('installationOverviews.0.credit.available_months', 3)
                ->where('installationOverviews.0.credit.payment_count', 1)
                ->where('ecosystemSummary.payments_count', 1)
                ->where('ecosystemSummary.total_available_credit_months', 3));
    }

    public function test_show_with_grace_period_subscription(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $this->makeSubscription($installation, ['status' => Subscription::STATUS_GRACE_PERIOD]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installationOverviews.0.access.accessible', true)
                ->where('installationOverviews.0.subscription.status', Subscription::STATUS_GRACE_PERIOD));
    }

    public function test_show_with_suspended_subscription(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $this->makeSubscription($installation, ['status' => Subscription::STATUS_SUSPENDED]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installationOverviews.0.access.status', 'suspended')
                ->where('installationOverviews.0.access.accessible', false));
    }

    public function test_show_with_terminated_subscription_displays_no_consumable_credit(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $subscription = $this->makeSubscription($installation, ['status' => Subscription::STATUS_TERMINATED]);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 90000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 6,
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installationOverviews.0.access.status', 'no_subscription')
                ->where('installationOverviews.0.subscription.status', Subscription::STATUS_TERMINATED)
                ->where('installationOverviews.0.subscription.is_current_non_terminated', false)
                ->where('installationOverviews.0.credit.available_months', 0)
                ->where('ecosystemSummary.total_available_credit_months', 0));
    }

    public function test_show_with_partially_consumed_credit(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $subscription = $this->makeSubscription($installation);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 90000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 6,
        ]);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'consumed_at' => '2026-10-05 10:00:00',
        ]);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => '2026-11-05 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installationOverviews.0.credit.available_months', 4)
                ->where('installationOverviews.0.credit.payment_count', 1));
    }

    public function test_show_separates_multiple_installations(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();
        $installationA = $this->makeInstallation($client, ['name' => 'Installation A', 'subdomain' => 'a-'.uniqid()]);
        $installationB = $this->makeInstallation($client, ['name' => 'Installation B', 'subdomain' => 'b-'.uniqid()]);
        $this->makeSubscription($installationA);
        $this->makeSubscription($installationB);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installationOverviews', 2)
                ->where('installationOverviews.0.installation.id', $installationB->id)
                ->where('installationOverviews.1.installation.id', $installationA->id));
    }

    public function test_show_does_not_trigger_n_plus_one_for_multiple_installations(): void
    {
        $user = User::factory()->create();
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

        $this->assertLessThanOrEqual(2, $subscriptionQueries);
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
