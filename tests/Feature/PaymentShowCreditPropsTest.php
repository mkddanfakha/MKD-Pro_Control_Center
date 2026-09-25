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

class PaymentShowCreditPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_exposes_one_month_credit_for_fifteen_thousand_payment(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Payments/Show')
                ->has('paymentCredit')
                ->where('paymentCredit.amount', 15000)
                ->where('paymentCredit.monthly_unit_amount', 15000)
                ->where('paymentCredit.credit_months_purchased', 1)
                ->where('paymentCredit.credit_months_remaining', 1)
                ->where('paymentCredit.consumptions_count', 0)
                ->where('paymentCredit.presents_consumable_credit', true)
                ->where('paymentCredit.is_exhausted', false)
                ->has('paymentCredit.consumptions', 0));
    }

    public function test_show_exposes_six_month_credit_for_ninety_thousand_payment(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 90000, 6);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentCredit.credit_months_purchased', 6)
                ->where('paymentCredit.credit_months_remaining', 6)
                ->where('paymentCredit.consumptions_count', 0));
    }

    public function test_show_exposes_partial_consumption_counts(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 90000, 6);

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
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentCredit.credit_months_purchased', 6)
                ->where('paymentCredit.consumptions_count', 2)
                ->where('paymentCredit.credit_months_remaining', 4)
                ->where('paymentCredit.presents_consumable_credit', true)
                ->where('paymentCredit.is_exhausted', false)
                ->has('paymentCredit.consumptions', 2)
                ->where('paymentCredit.consumptions.0.period_start', '2026-10-01 00:00:00')
                ->where('paymentCredit.consumptions.1.period_start', '2026-11-01 00:00:00'));
    }

    public function test_show_marks_fully_consumed_credit_as_exhausted(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 90000, 6, [
            'credit_exhausted_at' => '2026-12-01 12:00:00',
        ]);

        foreach ($this->consumptionPeriodFixtures() as $fixture) {
            SubscriptionPaymentConsumption::query()->create([
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => $fixture['period_start'],
                'period_end' => $fixture['period_end'],
                'consumed_at' => $fixture['consumed_at'],
            ]);
        }

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentCredit.credit_months_remaining', 0)
                ->where('paymentCredit.consumptions_count', 6)
                ->where('paymentCredit.is_exhausted', true)
                ->where('paymentCredit.presents_consumable_credit', false)
                ->where('paymentCredit.credit_exhausted_at', '2026-12-01 12:00:00')
                ->where('canRenewSubscription', false)
                ->has('paymentCredit.consumptions', 6));
    }

    public function test_refunded_payment_keeps_history_but_not_consumable(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 90000, 6, [
            'status' => Payment::STATUS_REFUNDED,
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
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentCredit.is_refunded', true)
                ->where('paymentCredit.credit_months_purchased', 6)
                ->where('paymentCredit.consumptions_count', 2)
                ->where('paymentCredit.presents_consumable_credit', false)
                ->where('canRenewSubscription', false)
                ->has('paymentCredit.consumptions', 2));
    }

    public function test_pending_payment_does_not_present_consumable_credit(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 90000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 6,
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentCredit.is_paid', false)
                ->where('paymentCredit.presents_consumable_credit', false)
                ->where('canRenewSubscription', false));
    }

    public function test_show_does_not_use_renewal_applied_at_for_consumption_count(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1, [
            'renewal_applied_at' => '2026-10-20 12:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentCredit.consumptions_count', 0)
                ->where('paymentCredit.credit_months_remaining', 1));
    }

    public function test_show_loads_consumptions_without_n_plus_one(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 90000, 6);

        foreach ($this->consumptionPeriodFixtures() as $fixture) {
            SubscriptionPaymentConsumption::query()->create([
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => $fixture['period_start'],
                'period_end' => $fixture['period_end'],
                'consumed_at' => $fixture['consumed_at'],
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('payments.show', $payment))->assertOk();

        $consumptionSelectQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), 'subscription_payment_consumptions'))
            ->count();

        $this->assertLessThan(6, $consumptionSelectQueries);
    }

    /**
     * @return list<array{period_start: string, period_end: string, consumed_at: string}>
     */
    private function consumptionPeriodFixtures(): array
    {
        return [
            ['period_start' => '2026-10-01 00:00:00', 'period_end' => '2026-10-31 23:59:59', 'consumed_at' => '2026-10-05 10:00:00'],
            ['period_start' => '2026-11-01 00:00:00', 'period_end' => '2026-11-30 23:59:59', 'consumed_at' => '2026-11-05 10:00:00'],
            ['period_start' => '2026-12-01 00:00:00', 'period_end' => '2026-12-31 23:59:59', 'consumed_at' => '2026-12-05 10:00:00'],
            ['period_start' => '2027-01-01 00:00:00', 'period_end' => '2027-01-31 23:59:59', 'consumed_at' => '2027-01-05 10:00:00'],
            ['period_start' => '2027-02-01 00:00:00', 'period_end' => '2027-02-28 23:59:59', 'consumed_at' => '2027-02-05 10:00:00'],
            ['period_start' => '2027-03-01 00:00:00', 'period_end' => '2027-03-31 23:59:59', 'consumed_at' => '2027-03-05 10:00:00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société show crédit paiement',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation show crédit',
            'subdomain' => 'pay-show-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePaidPayment(Subscription $subscription, int $amount, int $months, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ], $attributes));
    }
}
