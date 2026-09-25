<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SubscriptionCreditConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    public function test_single_month_payment_consumption_exhausts_credit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $consumption = $this->service->consumeCreditFromPayment($payment);

        $subscription->refresh();
        $payment->refresh();

        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame($payment->id, $consumption->payment_id);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, $payment->remainingCreditMonths());
        $this->assertNotNull($payment->credit_exhausted_at);
    }

    public function test_twelve_month_payment_allows_sequential_consumption(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 180000, 12);

        $this->service->consumeCreditFromPayment($payment);
        $payment->refresh();
        $this->assertSame(11, $payment->remainingCreditMonths());

        $this->service->consumeCreditFromPayment($payment->fresh());
        $payment->refresh();
        $this->assertSame(10, $payment->remainingCreditMonths());
        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
    }

    public function test_thirteenth_consumption_on_twelve_month_payment_is_rejected(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-01-01 00:00:00',
            'current_period_end' => '2026-01-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 180000, 12);

        for ($i = 0; $i < 12; $i++) {
            $this->service->consumeCreditFromPayment($payment->fresh());
        }

        $payment->refresh();
        $this->assertSame(0, $payment->remainingCreditMonths());
        $this->assertNotNull($payment->credit_exhausted_at);
        $this->assertNull($payment->renewal_applied_at);
        $this->assertSame(12, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('crédit');

        $this->service->consumeCreditFromPayment($payment->fresh());
    }

    public function test_refunded_payment_cannot_consume_credit(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => '2026-10-01 12:00:00',
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->consumeCreditFromPayment($payment);
    }

    public function test_refunded_payment_after_partial_consumption_keeps_history_and_blocks_new_consumption(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 45000, 3);

        $this->service->consumeCreditFromPayment($payment);

        $payment->refresh();
        $this->assertSame(3, (int) $payment->credit_months_purchased);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());

        $payment->update([
            'status' => Payment::STATUS_REFUNDED,
        ]);

        $payment->refresh();
        $this->assertSame(3, (int) $payment->credit_months_purchased);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->consumeCreditFromPayment($payment->fresh());
    }

    public function test_pending_payment_cannot_consume_credit(): void
    {
        $subscription = $this->makeSubscription();
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->consumeCreditFromPayment($payment);
    }

    public function test_terminated_subscription_cannot_consume_credit(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-10-01 00:00:00',
        ]);

        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->consumeCreditFromPayment($payment);
    }

    public function test_two_consecutive_consumptions_open_two_different_periods(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 45000, 3);

        $first = $this->service->consumeCreditFromPayment($payment);
        $second = $this->service->consumeCreditFromPayment($payment->fresh());

        $this->assertSame('2026-11-01 00:00:00', $first->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 00:00:00', $second->period_start->format('Y-m-d H:i:s'));
        $this->assertNotSame(
            $first->period_start->format('Y-m-d H:i:s'),
            $second->period_start->format('Y-m-d H:i:s'),
        );
    }

    public function test_same_subscription_period_cannot_be_consumed_twice(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 15000, 1);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => '2026-11-01 10:00:00',
        ]);

        $periodStartBefore = $subscription->current_period_start->format('Y-m-d H:i:s');
        $periodEndBefore = $subscription->current_period_end->format('Y-m-d H:i:s');

        try {
            $this->service->consumeCreditFromPayment($payment);
            $this->fail('Expected SubscriptionRenewalException was not thrown.');
        } catch (SubscriptionRenewalException) {
            // expected
        }

        $subscription->refresh();
        $this->assertSame($periodStartBefore, $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame($periodEndBefore, $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_high_amount_payment_is_allowed_when_credit_fields_match(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 180000, 12);

        $this->assertTrue($this->service->canConsumeCreditFromPayment($payment));

        $this->service->consumeCreditFromPayment($payment);

        $this->assertSame('2026-11-01 00:00:00', $subscription->fresh()->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_currency_mismatch_is_rejected_for_consumption(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1, [
            'currency' => 'EUR',
        ]);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('devise');

        $this->service->consumeCreditFromPayment($payment);
    }

    public function test_subscription_period_is_rolled_back_when_consumption_insert_fails(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $periodStartBefore = $subscription->current_period_start->format('Y-m-d H:i:s');
        $periodEndBefore = $subscription->current_period_end->format('Y-m-d H:i:s');

        Event::listen('eloquent.creating: '.SubscriptionPaymentConsumption::class, function () {
            throw new \RuntimeException('Simulated consumption persistence failure.');
        });

        try {
            $this->service->consumeCreditFromPayment($payment);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException) {
            // expected
        } finally {
            Event::forget('eloquent.creating: '.SubscriptionPaymentConsumption::class);
        }

        $subscription->refresh();
        $this->assertSame($periodStartBefore, $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame($periodEndBefore, $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_two_payments_can_fund_two_different_months_sequentially(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $paymentA = $this->makePaidPayment($subscription, 90000, 6);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3);

        $consumptionA = $this->service->consumeCreditFromPayment($paymentA);
        $consumptionB = $this->service->consumeCreditFromPayment($paymentB);

        $this->assertSame('2026-11-01 00:00:00', $consumptionA->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 00:00:00', $consumptionB->period_start->format('Y-m-d H:i:s'));
        $this->assertSame($paymentA->id, $consumptionA->payment_id);
        $this->assertSame($paymentB->id, $consumptionB->payment_id);
    }

    public function test_alternate_payments_a_b_a_use_distinct_periods_and_own_credit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $paymentA = $this->makePaidPayment($subscription, 90000, 6);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3);

        $consumptionA1 = $this->service->consumeCreditFromPayment($paymentA);
        $consumptionB1 = $this->service->consumeCreditFromPayment($paymentB);
        $consumptionA2 = $this->service->consumeCreditFromPayment($paymentA->fresh());

        $periods = [
            $consumptionA1->period_start->format('Y-m-d H:i:s'),
            $consumptionB1->period_start->format('Y-m-d H:i:s'),
            $consumptionA2->period_start->format('Y-m-d H:i:s'),
        ];

        $this->assertSame($periods, array_values(array_unique($periods)));
        $this->assertSame('2026-11-01 00:00:00', $periods[0]);
        $this->assertSame('2026-12-01 00:00:00', $periods[1]);
        $this->assertSame('2027-01-01 00:00:00', $periods[2]);

        $paymentA->refresh();
        $paymentB->refresh();

        $this->assertSame(4, $paymentA->remainingCreditMonths());
        $this->assertSame(2, $paymentB->remainingCreditMonths());
        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count());
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentB->id)->count());
    }

    public function test_historical_payment_unchanged_when_subscription_amount_increases_to_20000(): void
    {
        $subscription = $this->makeSubscription(['amount' => 15000]);
        $payment = $this->makePaidPayment($subscription, 90000, 6);

        $this->updateSubscriptionAmountViaHttp($subscription, 20000);

        $payment->refresh();
        $subscription->refresh();

        $this->assertSame(20000, (int) $subscription->amount);
        $this->assertSame(90000, (int) $payment->amount);
        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame(6, (int) $payment->credit_months_purchased);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
    }

    public function test_new_payment_after_price_change_uses_current_subscription_amount(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $this->updateSubscriptionAmountViaHttp($subscription, 20000);

        $response = $this->actingAs($user)->post(route('payments.store'), [
            'subscription_id' => $subscription->id,
            'amount' => 60000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-20 12:00:00',
            'payment_method' => 'wave',
            'reference' => 'REF-PRICE-CHANGE',
        ]);

        $response->assertRedirect();

        $payment = Payment::query()->where('subscription_id', $subscription->id)->sole();

        $this->assertSame(60000, (int) $payment->amount);
        $this->assertSame(20000, (int) $payment->monthly_unit_amount);
        $this->assertSame(3, (int) $payment->credit_months_purchased);
    }

    public function test_existing_credit_consumption_after_price_change_remains_month_based(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 15000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 90000, 6);

        $this->updateSubscriptionAmountViaHttp($subscription, 20000);

        $consumption = $this->service->consumeCreditFromPayment($payment->fresh());

        $payment->refresh();
        $subscription->refresh();

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
        $this->assertSame('2026-11-01 00:00:00', $consumption->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $consumption->period_end->format('Y-m-d H:i:s'));
        $this->assertSame(5, $payment->remainingCreditMonths());
        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame(6, (int) $payment->credit_months_purchased);
        $this->assertSame(20000, (int) $subscription->amount);
    }

    public function test_legacy_renew_compares_payment_amount_to_current_subscription_amount(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 15000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $subscription->update(['amount' => 20000]);
        $subscription->refresh();

        $this->assertSame(15000, (int) $payment->fresh()->amount);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('montant du paiement');

        $this->service->renew($subscription, $payment->fresh());
    }

    private function updateSubscriptionAmountViaHttp(Subscription $subscription, int $amount): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            [
                'installation_id' => $subscription->installation_id,
                'amount' => $amount,
                'currency' => $subscription->currency,
                'status' => $subscription->status,
                'notes' => $subscription->notes,
            ],
        )->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Crédit',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Crédit',
            'subdomain' => 'credit-'.uniqid(),
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
        $payment = Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ], $attributes));

        if ((int) $payment->amount !== $months * (int) $subscription->amount) {
            $this->service->applyPaymentCreditFields($payment, $subscription);
            $payment->refresh();
        }

        return $payment;
    }
}
