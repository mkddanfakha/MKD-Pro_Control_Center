<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRenewalTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    public function test_paid_payment_renews_subscription_successfully(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 00:00:00',
        ]);

        $renewed = $this->service->renewFromPayment($payment);

        $this->assertSame('2026-11-01 00:00:00', $renewed->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $renewed->current_period_end->format('Y-m-d H:i:s'));
        $this->assertCreditPathConsumedSingleMonth($payment);
    }

    public function test_pending_payment_is_rejected_for_renewal(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renewFromPayment($payment);
    }

    public function test_failed_payment_is_rejected_for_renewal(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_FAILED,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renewFromPayment($payment);
    }

    public function test_payment_from_another_subscription_is_rejected(): void
    {
        $subscriptionA = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $subscriptionB = $this->makeSubscription([
            'current_period_start' => '2026-09-01 00:00:00',
            'current_period_end' => '2026-09-30 23:59:59',
        ]);

        $payment = $this->makePayment($subscriptionA, [
            'status' => Payment::STATUS_PAID,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renew($subscriptionB, $payment);
    }

    public function test_double_renewal_with_same_payment_is_rejected(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
        ]);

        $this->service->renewFromPayment($payment);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renewFromPayment($payment->fresh());
    }

    public function test_renewal_resets_grace_and_suspension_and_sets_active_status(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-05 23:59:59',
            'suspended_at' => '2026-11-01 08:00:00',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
        ]);

        $renewed = $this->service->renewFromPayment($payment);

        $this->assertSame(Subscription::STATUS_ACTIVE, $renewed->status);
        $this->assertNull($renewed->grace_period_ends_at);
        $this->assertNull($renewed->suspended_at);
    }

    public function test_terminated_subscription_renewal_is_rejected(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => now(),
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renewFromPayment($payment);
    }

    public function test_renew_subscription_route_requires_authentication(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
        ]);

        $response = $this->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect();
    }

    public function test_renew_subscription_route_renews_when_authenticated(): void
    {
        $user = User::factory()->create();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
        ]);

        $response = $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect(route('payments.show', $payment));
        $response->assertSessionHas('success');

        $this->assertSame('2026-11-01 00:00:00', $subscription->fresh()->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_renewal_is_allowed_when_amount_and_currency_match_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 16000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'amount' => 16000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
        ]);

        $this->assertTrue($this->service->canRenewFromPayment($payment));
        $this->assertNotEmpty($this->service->previewRenewalFromPayment($payment));

        $this->service->renewFromPayment($payment);

        $this->assertCreditPathConsumedSingleMonth($payment);
    }

    public function test_renewal_is_rejected_when_payment_amount_is_lower_than_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 16000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'amount' => 15000,
            'status' => Payment::STATUS_PAID,
        ]);

        $this->assertFalse($this->service->canRenewFromPayment($payment));

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('crédit');

        $this->service->renewFromPayment($payment);
    }

    public function test_renewal_is_rejected_when_payment_amount_is_not_a_multiple_of_monthly_tariff(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'amount' => 16000,
            'status' => Payment::STATUS_PAID,
        ]);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('crédit');

        $this->service->renewFromPayment($payment);
    }

    public function test_renewal_is_rejected_when_payment_currency_differs_from_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('devise');

        $this->service->renewFromPayment($payment);
    }

    public function test_existing_inconsistent_payment_like_dev_number_three_is_rejected_for_renewal(): void
    {
        $user = User::factory()->create();

        $subscription = $this->makeSubscription([
            'amount' => 16000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'amount' => 15000,
            'status' => Payment::STATUS_PAID,
        ]);

        $periodBefore = $subscription->current_period_end->format('Y-m-d H:i:s');

        $response = $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect(route('payments.show', $payment));
        $response->assertSessionHas('error');

        $subscription->refresh();
        $payment->refresh();

        $this->assertSame($periodBefore, $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertNull($payment->renewal_applied_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_failed')->count());
    }

    public function test_amount_mismatch_does_not_partially_update_subscription_period(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 16000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = $this->makePayment($subscription, [
            'amount' => 15000,
            'status' => Payment::STATUS_PAID,
        ]);

        $periodStartBefore = $subscription->current_period_start->format('Y-m-d H:i:s');
        $periodEndBefore = $subscription->current_period_end->format('Y-m-d H:i:s');

        try {
            $this->service->renewFromPayment($payment);
            $this->fail('Expected SubscriptionRenewalException was not thrown.');
        } catch (SubscriptionRenewalException) {
            // expected
        }

        $subscription->refresh();

        $this->assertSame($periodStartBefore, $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame($periodEndBefore, $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertNull($payment->fresh()->renewal_applied_at);
    }

    public function test_renewal_is_allowed_when_payment_period_dates_are_both_null(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => null,
            'period_end' => null,
        ]);

        $this->service->renewFromPayment($payment);

        $this->assertCreditPathConsumedSingleMonth($payment);
    }

    public function test_renewal_is_allowed_when_payment_period_days_match_subscription(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]);

        $this->service->renewFromPayment($payment);

        $this->assertSame('2026-11-01 00:00:00', $subscription->fresh()->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_renewal_is_allowed_when_payment_period_has_same_days_with_different_times(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-10-01 18:30:00',
            'period_end' => '2026-10-31 08:15:00',
        ]);

        $this->assertTrue($this->service->canRenewFromPayment($payment));
        $this->service->renewFromPayment($payment);

        $this->assertCreditPathConsumedSingleMonth($payment);
    }

    public function test_renewal_is_rejected_when_payment_period_start_day_differs(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-09-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_payment_period_end_day_differs(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-11-01 00:00:00',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_only_payment_period_start_is_set(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => null,
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_only_payment_period_end_is_set(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => null,
            'period_end' => '2026-10-31 23:59:59',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_payment_period_is_entirely_before_subscription_period(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-08-01 00:00:00',
            'period_end' => '2026-08-31 23:59:59',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_payment_period_is_entirely_after_subscription_period(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-12-01 00:00:00',
            'period_end' => '2026-12-31 23:59:59',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_payment_period_partially_overlaps_subscription_period(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-10-15 00:00:00',
            'period_end' => '2026-11-15 00:00:00',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_renewal_is_rejected_when_payment_period_is_inverted_in_database(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-10-31 00:00:00',
            'period_end' => '2026-10-01 00:00:00',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_period_mismatch_produces_consistent_refusal_in_legacy_renew_path(): void
    {
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-09-01 00:00:00',
            'period_end' => '2026-09-30 23:59:59',
        ]);

        $this->assertLegacyRenewalRejectedByService($subscription, $payment);
    }

    public function test_period_mismatch_via_http_route_consumes_credit_until_controller_is_updated(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscriptionWithOctoberPeriod();

        $payment = $this->makePayment($subscription, [
            'period_start' => '2026-09-01 00:00:00',
            'period_end' => '2026-09-30 23:59:59',
        ]);

        $response = $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect(route('payments.show', $payment));
        $response->assertSessionHas('success');
        $this->assertSame('2026-11-01 00:00:00', $subscription->fresh()->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
    }

    private function subscriptionWithOctoberPeriod(): Subscription
    {
        return $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
    }

    private function assertCreditPathConsumedSingleMonth(Payment $payment): void
    {
        $payment->refresh();

        $this->assertNull($payment->renewal_applied_at);
        $this->assertSame(
            1,
            SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count(),
        );
        $this->assertNotNull($payment->credit_exhausted_at);
    }

    private function assertLegacyRenewalRejectedByService(Subscription $subscription, Payment $payment): void
    {
        $periodStartBefore = $subscription->current_period_start?->format('Y-m-d H:i:s');
        $periodEndBefore = $subscription->current_period_end?->format('Y-m-d H:i:s');

        try {
            $this->service->renew($subscription, $payment);
            $this->fail('Expected SubscriptionRenewalException was not thrown.');
        } catch (SubscriptionRenewalException) {
            // expected
        }

        $subscription->refresh();
        $this->assertSame($periodStartBefore, $subscription->current_period_start?->format('Y-m-d H:i:s'));
        $this->assertSame($periodEndBefore, $subscription->current_period_end?->format('Y-m-d H:i:s'));
        $this->assertNull($payment->fresh()->renewal_applied_at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
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
    private function makePayment(Subscription $subscription, array $attributes = []): Payment
    {
        $data = array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ], $attributes);

        if (in_array($data['status'], [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
            $data['paid_at'] = null;
        }

        $amount = (int) $data['amount'];
        $unit = (int) $subscription->amount;

        if (
            ! array_key_exists('monthly_unit_amount', $data)
            && ! array_key_exists('credit_months_purchased', $data)
            && $unit > 0
            && $amount % $unit === 0
        ) {
            $data['monthly_unit_amount'] = $unit;
            $data['credit_months_purchased'] = (int) ($amount / $unit);
        }

        return Payment::query()->create($data);
    }
}
