<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationShowOperationalTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_includes_client_on_installation(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installation.client.id', $installation->client_id)
                ->where('installation.client.company_name', 'Société Test'));
    }

    public function test_show_exposes_installation_status(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['status' => 'suspended']);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installation.status', 'suspended'));
    }

    public function test_show_exposes_access_from_installation_access_service(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription.status', Subscription::STATUS_GRACE_PERIOD));
    }

    public function test_show_with_active_subscription_includes_subscription_link_data(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $subscription = Subscription::query()
            ->where('installation_id', $installation->id)
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('current_subscription')
                ->where('current_subscription.id', $subscription->id)
                ->where('current_subscription.status', Subscription::STATUS_ACTIVE));
    }

    public function test_show_without_active_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription', null)
                ->has('payments.data', 0));
    }

    public function test_show_exposes_credit_months_on_payment_rows(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription($installation);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 90000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-25 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 6,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.credit_months_purchased', 6)
                ->missing('credit'));
    }

    public function test_show_exposes_payments_summary(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription($installation);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 12:00:00',
            'created_at' => '2026-09-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 90000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-25 12:00:00',
            'created_at' => '2026-09-25 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 6,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 2)
                ->where('payments.data.0.amount', 90000)
                ->where('payments.data.0.currency', 'XOF')
                ->where('payments.data.0.paid_at', '2026-09-25 12:00:00'));
    }

    public function test_show_does_not_expose_module_assignments_on_admin_detail(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $module = Module::query()->create([
            'name' => 'Module POS',
            'slug' => 'pos-'.uniqid(),
            'currency' => 'XOF',
            'price' => 5000,
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);

        InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
            'version' => '1.2.0',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('modules'));
    }

    public function test_show_does_not_use_terminated_subscription_as_current(): void
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
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription', null));
    }

    public function test_show_includes_payments_summary_for_terminated_subscription_history(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $terminated = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $terminated->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription', null)
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.amount', 45000));
    }

    public function test_show_does_not_trigger_obvious_n_plus_one(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription($installation);

        for ($index = 0; $index < 5; $index++) {
            Payment::query()->create([
                'subscription_id' => $subscription->id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Payment::STATUS_PAID,
                'paid_at' => '2026-09-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' 12:00:00',
                'monthly_unit_amount' => 15000,
                'credit_months_purchased' => 1,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('installations.show', $installation))->assertOk();

        $paymentSelectQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), 'payments'))
            ->count();

        $this->assertLessThanOrEqual(4, $paymentSelectQueries);
    }

    /**
     * @param  array<string, mixed>  $subscriptionAttributes
     */
    private function makeInstallationWithSubscription(array $subscriptionAttributes = []): Installation
    {
        $installation = $this->makeInstallation();
        $this->makeSubscription($installation, $subscriptionAttributes);

        return $installation;
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
        ], $attributes));
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
