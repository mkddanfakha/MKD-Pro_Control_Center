<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSubscriptionStorePayload;
use Tests\TestCase;

/**
 * Gouvernance du crédit mensuel (TASK 269).
 *
 * Scénarios déjà couverts ailleurs : FIFO → SubscriptionCreditFifoTest ;
 * concurrence → PaymentConcurrencyTest ; destruction → PaymentDestroyConsumptionGuardTest ;
 * HTTP crédit détaillé → PaymentCreditHttpTest ; consommation → SubscriptionCreditConsumptionTest.
 */
class PaymentMonthlyCreditGovernanceTest extends TestCase
{
    use BuildsSubscriptionStorePayload;
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_store_grants_two_months_for_30000_at_15000_monthly_unit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $this->actingAs($user)->post(route('payments.store'), $this->storePayload($subscription, [
            'amount' => 30000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]))->assertRedirect();

        $payment = Payment::query()->sole();
        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame(2, (int) $payment->credit_months_purchased);
    }

    public function test_store_rejects_40000_when_monthly_unit_is_15000_without_fractional_credit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $this->actingAs($user)->post(route('payments.store'), $this->storePayload($subscription, [
            'amount' => 40000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]))->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_custom_subscription_tariff_20000_yields_two_months_for_40000_payment(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 20000,
        ]));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $this->assertSame(15000, (int) $offerVersion->fresh()->price);
        $this->assertSame(20000, (int) $subscription->amount);

        $this->actingAs($user)->post(route('payments.store'), $this->storePayload($subscription, [
            'amount' => 40000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]))->assertRedirect();

        $payment = Payment::query()->sole();
        $this->assertSame(20000, (int) $payment->monthly_unit_amount);
        $this->assertSame(2, (int) $payment->credit_months_purchased);
    }

    public function test_subscription_amount_change_does_not_alter_existing_payment_credit_months_purchased(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $this->actingAs($user)->post(route('payments.store'), $this->storePayload($subscription, [
            'amount' => 45000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $payment = Payment::query()->sole();
        $this->assertSame(3, (int) $payment->credit_months_purchased);

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $subscription->installation_id,
            'amount' => 22000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start?->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end?->format('Y-m-d H:i:s'),
        ]);

        $payment->refresh();
        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame(3, (int) $payment->credit_months_purchased);
        $this->assertSame(45000, (int) $payment->amount);
    }

    public function test_calculate_payment_credit_fields_rejects_non_multiple_without_rounding(): void
    {
        $subscription = $this->makeSubscription(['amount' => 15000]);

        try {
            $this->service->calculatePaymentCreditFields($subscription, 40000, 'XOF');
            $rejected = false;
        } catch (SubscriptionRenewalException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    public function test_pending_payment_is_not_consumable(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1, [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => null,
        ]);

        $this->assertFalse($this->service->canConsumeCreditFromPayment($payment));
    }

    public function test_failed_payment_is_not_consumable(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1, [
            'status' => Payment::STATUS_FAILED,
            'paid_at' => null,
        ]);

        $this->assertFalse($this->service->canConsumeCreditFromPayment($payment));
    }

    public function test_paid_payment_exposes_expected_remaining_credit(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        $this->assertTrue($this->service->canConsumeCreditFromPayment($payment));
        $this->assertSame(3, $payment->remainingCreditMonths());
    }

    public function test_three_month_payment_allows_exactly_three_consumptions(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        for ($i = 0; $i < 3; $i++) {
            $this->service->consumeCreditFromPayment($payment->fresh());
        }

        $this->expectException(SubscriptionRenewalException::class);
        $this->service->consumeCreditFromPayment($payment->fresh());
    }

    public function test_credit_exhausted_at_stays_null_until_final_consumption(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        $this->assertNull($payment->credit_exhausted_at);

        $this->service->consumeCreditFromPayment($payment);
        $payment->refresh();
        $this->assertNull($payment->credit_exhausted_at);
        $this->assertSame(2, $payment->remainingCreditMonths());

        $this->service->consumeCreditFromPayment($payment->fresh());
        $payment->refresh();
        $this->assertNull($payment->credit_exhausted_at);

        $this->service->consumeCreditFromPayment($payment->fresh());
        $payment->refresh();
        $this->assertNotNull($payment->credit_exhausted_at);
        $this->assertSame(0, $payment->remainingCreditMonths());
    }

    public function test_refunded_payment_keeps_historical_consumption_rows(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        $this->service->consumeCreditFromPayment($payment);
        $this->service->consumeCreditFromPayment($payment->fresh());

        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());

        $payment->update([
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => $payment->paid_at,
        ]);

        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
        $this->assertFalse($this->service->canConsumeCreditFromPayment($payment->fresh()));
    }

    public function test_targeted_consumption_respects_payment_remaining_credit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->service->consumeCreditFromPayment($payment);

        $this->expectException(SubscriptionRenewalException::class);
        $this->service->consumeCreditFromPayment($payment->fresh());
    }

    public function test_terminated_subscription_rejects_new_credit_consumption(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-30 23:59:59',
            'current_period_start' => '2026-09-01 00:00:00',
            'current_period_end' => '2026-09-30 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->expectException(SubscriptionRenewalException::class);
        $this->service->consumeNextCreditForSubscription($subscription);
    }

    public function test_each_consumption_advances_subscription_by_one_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 30000, 2);

        $this->service->consumeCreditFromPayment($payment);
        $subscription->refresh();
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->service->consumeCreditFromPayment($payment->fresh());
        $subscription->refresh();
        $this->assertSame('2026-12-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_credit_consumption_emits_subscription_credit_consumed_audit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription))
            ->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storePayload(Subscription $subscription, array $overrides = []): array
    {
        return array_merge([
            'subscription_id' => $subscription->id,
            'amount' => (int) $subscription->amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PENDING,
        ], $overrides);
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
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'installation_id' => $this->makeInstallation()->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client Credit Gov',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'gov-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
