<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSubscriptionStorePayload;
use Tests\TestCase;

/**
 * Gouvernance du renouvellement mensuel via crédit Payment (TASK 270).
 *
 * FIFO, concurrence, statuts et scénarios détaillés : SubscriptionCreditFifoTest,
 * SubscriptionCreditConsumptionTest, SubscriptionCreditConsumptionHttpTest,
 * PaymentRenewalTest, PaymentConcurrencyTest.
 */
class PaymentSubscriptionRenewalGovernanceTest extends TestCase
{
    use BuildsSubscriptionStorePayload;
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    public function test_fifo_consume_credit_emits_subscription_credit_consumed_only(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription))
            ->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
    }

    public function test_targeted_renew_subscription_emits_payment_renewal_applied_only(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)->post(route('payments.renew-subscription', $payment))
            ->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
    }

    public function test_successful_consumption_does_not_set_renewal_applied_at_on_payment(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->service->consumeCreditFromPayment($payment);

        $payment->refresh();
        $this->assertNull($payment->renewal_applied_at);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_consumption_period_fields_match_subscription_current_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $consumption = $this->service->consumeCreditFromPayment($payment);

        $subscription->refresh();

        $this->assertSame(
            $subscription->current_period_start->format('Y-m-d H:i:s'),
            $consumption->period_start->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $subscription->current_period_end->format('Y-m-d H:i:s'),
            $consumption->period_end->format('Y-m-d H:i:s'),
        );
    }

    public function test_two_consecutive_consumptions_produce_contiguous_non_overlapping_periods(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 30000, 2);

        $first = $this->service->consumeCreditFromPayment($payment);
        $second = $this->service->consumeCreditFromPayment($payment->fresh());

        $this->assertSame('2026-11-01 00:00:00', $first->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $first->period_end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 00:00:00', $second->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 23:59:59', $second->period_end->format('Y-m-d H:i:s'));

        $this->assertTrue($first->period_end->lt($second->period_start));
    }

    public function test_custom_tariff_payment_renews_two_months_without_catalogue_price(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 20000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 40000, 2, [
            'monthly_unit_amount' => 20000,
        ]);

        $this->service->consumeCreditFromPayment($payment);
        $this->service->consumeCreditFromPayment($payment->fresh());

        $subscription->refresh();
        $this->assertSame('2026-12-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(20000, (int) $payment->fresh()->monthly_unit_amount);
    }

    public function test_terminated_subscription_http_consume_credit_is_rejected_without_reactivation(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-30 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription))
            ->assertRedirect()
            ->assertSessionHas('error');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_credit_consumption_never_creates_new_payment(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 45000, 3);

        $countBefore = Payment::query()->count();

        $this->service->consumeNextCreditForSubscription($subscription);

        $this->assertSame($countBefore, Payment::query()->count());
    }

    public function test_subscription_store_still_does_not_create_payment_during_renewal_workflow(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id))
            ->assertRedirect();

        $this->assertSame(0, Payment::query()->count());
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
            'monthly_unit_amount' => (int) ($attributes['monthly_unit_amount'] ?? $subscription->amount),
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
            'company_name' => 'Client Renewal Gov',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'ren-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
