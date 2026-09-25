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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $installation = $this->makeInstallation(['status' => 'suspended']);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installation.status', 'suspended'));
    }

    public function test_show_exposes_access_from_installation_access_service(): void
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
                ->where('access.subscription_status', Subscription::STATUS_GRACE_PERIOD));
    }

    public function test_show_with_active_subscription_includes_subscription_link_data(): void
    {
        $user = User::factory()->create();
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
                ->has('lastSubscription')
                ->where('lastSubscription.id', $subscription->id)
                ->where('lastSubscription.status', Subscription::STATUS_ACTIVE));
    }

    public function test_show_without_active_subscription(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lastSubscription', null)
                ->where('credit', null));
    }

    public function test_show_exposes_credit_summary_from_subscription_service(): void
    {
        $user = User::factory()->create();
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
                ->has('credit')
                ->where('credit.available_months', 6)
                ->where('credit.payment_count', 1)
                ->missing('credit.payments'));
    }

    public function test_show_exposes_zero_credit_when_no_remaining_months(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription($installation);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $payment = Payment::query()->where('subscription_id', $subscription->id)->firstOrFail();

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-09-01 00:00:00',
            'period_end' => '2026-09-30 23:59:59',
            'consumed_at' => '2026-09-02 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 0)
                ->where('credit.payment_count', 1));
    }

    public function test_show_exposes_payments_summary(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription($installation);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

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
                ->has('paymentsSummary')
                ->where('paymentsSummary.count', 2)
                ->where('paymentsSummary.last_payment.amount', 90000)
                ->where('paymentsSummary.last_payment.currency', 'XOF')
                ->where('paymentsSummary.last_payment.paid_at', '2026-09-25 12:00:00'));
    }

    public function test_show_exposes_assigned_modules(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = Module::query()->create([
            'name' => 'Module POS',
            'slug' => 'pos-'.uniqid(),
            'currency' => 'XOF',
            'price' => 5000,
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);

        $assignment = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
            'version' => '1.2.0',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('modules', 1)
                ->where('modules.0.id', $assignment->id)
                ->where('modules.0.status', InstallationModule::STATUS_ACTIVE)
                ->where('modules.0.version', '1.2.0')
                ->where('modules.0.module.name', 'Module POS')
                ->where('modules.0.module.price', 5000));
    }

    public function test_show_does_not_use_terminated_subscription_as_current(): void
    {
        $user = User::factory()->create();
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
                ->where('lastSubscription', null)
                ->where('credit', null)
                ->where('access.status', 'no_subscription'));
    }

    public function test_show_does_not_trigger_obvious_n_plus_one(): void
    {
        $user = User::factory()->create();
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

        for ($moduleIndex = 0; $moduleIndex < 3; $moduleIndex++) {
            $module = Module::query()->create([
                'name' => 'Module '.$moduleIndex,
                'slug' => 'mod-'.$moduleIndex.'-'.uniqid(),
                'currency' => 'XOF',
                'status' => Module::STATUS_ACTIVE,
                'sort_order' => 0,
            ]);

            InstallationModule::query()->create([
                'installation_id' => $installation->id,
                'module_id' => $module->id,
                'status' => InstallationModule::STATUS_ACTIVE,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('installations.show', $installation))->assertOk();

        $paymentSelectQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), ' from `payments`'))
            ->count();

        $moduleSelectQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), ' from `modules`'))
            ->count();

        $this->assertLessThanOrEqual(3, $paymentSelectQueries);
        $this->assertLessThanOrEqual(2, $moduleSelectQueries);
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
