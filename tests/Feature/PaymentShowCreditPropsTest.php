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
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Show')
                ->has('credit')
                ->where('credit.amount', 15000)
                ->where('credit.monthly_unit_amount', 15000)
                ->where('credit.credit_months_purchased', 1)
                ->where('credit.credit_months_remaining', 1)
                ->where('credit.consumptions_count', 0)
                ->where('credit.presents_consumable_credit', true)
                ->where('credit.is_exhausted', false)
                ->has('consumptions', 0));
    }

    public function test_show_exposes_six_month_credit_for_ninety_thousand_payment(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 90000, 6);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.credit_months_purchased', 6)
                ->where('credit.credit_months_remaining', 6)
                ->where('credit.consumptions_count', 0));
    }

    public function test_show_exposes_partial_consumption_counts(): void
    {
        $user = $this->controlCenterAdminUser();
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
                ->where('credit.credit_months_purchased', 6)
                ->where('credit.consumptions_count', 2)
                ->where('credit.credit_months_remaining', 4)
                ->where('credit.presents_consumable_credit', true)
                ->where('credit.is_exhausted', false)
                ->has('consumptions', 2)
                ->where('consumptions.0.period_start', '2026-10-01 00:00:00')
                ->where('consumptions.1.period_start', '2026-11-01 00:00:00'));
    }

    public function test_show_marks_fully_consumed_credit_as_exhausted(): void
    {
        $user = $this->controlCenterAdminUser();
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
                ->where('credit.credit_months_remaining', 0)
                ->where('credit.consumptions_count', 6)
                ->where('credit.is_exhausted', true)
                ->where('credit.presents_consumable_credit', false)
                ->where('credit.credit_exhausted_at', '2026-12-01 12:00:00')
                ->has('consumptions', 6));
    }

    public function test_refunded_payment_keeps_history_but_not_consumable(): void
    {
        $user = $this->controlCenterAdminUser();
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
                ->where('credit.is_refunded', true)
                ->where('credit.credit_months_purchased', 6)
                ->where('credit.consumptions_count', 2)
                ->where('credit.presents_consumable_credit', false)
                ->has('consumptions', 2));
    }

    public function test_show_terminated_subscription_exposes_arithmetic_credit_but_not_renewable(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-10-01 00:00:00',
        ]);
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Show')
                ->where('subscription.status', Subscription::STATUS_TERMINATED)
                ->where('credit.credit_months_purchased', 3)
                ->where('credit.credit_months_remaining', 3)
                ->where('credit.presents_consumable_credit', false)
);
    }

    public function test_show_terminated_subscription_with_partially_consumed_payment_exposes_remaining_arithmetic_credit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 90000, 6);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'consumed_at' => '2026-10-05 10:00:00',
        ]);

        $subscription->update([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);

        $consumptionsBefore = SubscriptionPaymentConsumption::query()
            ->where('payment_id', $payment->id)
            ->count();

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.status', Subscription::STATUS_TERMINATED)
                ->where('credit.consumptions_count', 1)
                ->where('credit.credit_months_remaining', 5)
                ->where('credit.presents_consumable_credit', false)
);

        $this->assertSame(
            $consumptionsBefore,
            SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count(),
        );
    }

    public function test_show_terminated_refunded_payment_does_not_present_consumable_credit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-10-01 00:00:00',
        ]);
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

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.is_refunded', true)
                ->where('credit.credit_months_remaining', 5)
                ->where('credit.presents_consumable_credit', false)
);
    }

    public function test_show_terminated_pending_payment_does_not_present_consumable_credit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-10-01 00:00:00',
        ]);
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.status', Subscription::STATUS_TERMINATED)
                ->where('credit.credit_months_purchased', 3)
                ->where('credit.presents_consumable_credit', false)
);
    }

    public function test_pending_payment_does_not_present_consumable_credit(): void
    {
        $user = $this->controlCenterAdminUser();
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
                ->where('credit.is_paid', false)
                ->where('credit.presents_consumable_credit', false)
);
    }

    public function test_show_does_not_use_renewal_applied_at_for_consumption_count(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1, [
            'renewal_applied_at' => '2026-10-20 12:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.consumptions_count', 0)
                ->where('credit.credit_months_remaining', 1));
    }

    public function test_show_loads_consumptions_without_n_plus_one(): void
    {
        $user = $this->controlCenterAdminUser();
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
