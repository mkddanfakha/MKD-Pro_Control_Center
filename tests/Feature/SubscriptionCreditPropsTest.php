<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionCreditPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_exposes_one_month_credit_for_single_paid_payment(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Show')
                ->has('credit')
                ->where('credit.available_months', 1)
                ->where('credit.payment_count', 1)
                ->has('credit.payments', 1)
                ->where('credit.payments.0.id', $payment->id)
                ->where('credit.payments.0.amount', 15000)
                ->where('credit.payments.0.monthly_unit_amount', 15000)
                ->where('credit.payments.0.credit_months_purchased', 1)
                ->where('credit.payments.0.credit_months_remaining', 1)
                ->where('credit.payments.0.consumptions_count', 0)
                ->where('credit.payments.0.is_refunded', false));
    }

    public function test_show_exposes_multi_month_credit_without_consumption(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 45000, 3);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 3)
                ->where('credit.payments.0.credit_months_remaining', 3));
    }

    public function test_show_reflects_partial_consumption(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 2)
                ->where('credit.payments.0.credit_months_remaining', 2)
                ->where('credit.payments.0.consumptions_count', 1));
    }

    public function test_show_sums_multiple_payments_in_fifo_order(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);
        $paymentC = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-10 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 10)
                ->where('credit.payment_count', 3)
                ->where('credit.payments.0.id', $paymentA->id)
                ->where('credit.payments.1.id', $paymentB->id)
                ->where('credit.payments.2.id', $paymentC->id));
    }

    public function test_show_sums_remaining_credit_for_partially_consumed_payments(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-20 10:00:00',
        ]);

        for ($i = 0; $i < 2; $i++) {
            SubscriptionPaymentConsumption::query()->create([
                'payment_id' => $paymentA->id,
                'subscription_id' => $subscription->id,
                'period_start' => '2026-11-0'.($i + 1).' 00:00:00',
                'period_end' => '2026-11-3'.($i === 0 ? '0' : '0').' 23:59:59',
                'consumed_at' => now(),
            ]);
        }

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $paymentB->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-12-01 00:00:00',
            'period_end' => '2026-12-31 23:59:59',
            'consumed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 6)
                ->where('credit.payments.0.credit_months_remaining', 4)
                ->where('credit.payments.1.credit_months_remaining', 2));
    }

    public function test_show_excludes_refunded_payment_from_available_months(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePaidPayment($subscription, 45000, 3, [
            'status' => Payment::STATUS_REFUNDED,
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 0)
                ->where('credit.payments.0.is_refunded', true)
                ->where('credit.payments.0.credit_months_remaining', 3));
    }

    public function test_show_excludes_failed_and_pending_payments_from_available_months(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_FAILED,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 0)
                ->where('credit.payment_count', 2));
    }

    public function test_show_reports_zero_when_credit_fully_consumed(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        foreach (['2026-11-01 00:00:00', '2026-12-01 00:00:00', '2027-01-01 00:00:00'] as $index => $periodStart) {
            SubscriptionPaymentConsumption::query()->create([
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => '2026-11-30 23:59:59',
                'consumed_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 0)
                ->where('credit.payments.0.credit_months_remaining', 0)
                ->where('credit.payments.0.consumptions_count', 3));
    }

    public function test_show_reports_zero_consumable_credit_for_terminated_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => now(),
        ]);

        $this->makePaidPayment($subscription, 45000, 3);

        $this->actingAs($user)
            ->get(route('subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.available_months', 0)
                ->where('credit.payments.0.credit_months_remaining', 3));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société props crédit',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation props crédit',
            'subdomain' => 'credit-props-'.uniqid(),
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
