<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCreditConsumptionHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_consume_credit_route_applies_fifo_and_advances_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $periodEndBefore = $subscription->current_period_end->format('Y-m-d H:i:s');

        $response = $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $response->assertRedirect(route('subscriptions.show', $subscription));
        $response->assertSessionHas('success');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());

        $consumption = SubscriptionPaymentConsumption::query()->sole();
        $this->assertSame($paymentA->id, $consumption->payment_id);
        $this->assertSame('2026-11-01 00:00:00', $consumption->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $consumption->period_end->format('Y-m-d H:i:s'));

        $subscription->refresh();
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertNotSame($periodEndBefore, $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
    }

    public function test_consume_credit_keeps_using_partially_consumed_payment_until_exhausted(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription))->assertRedirect();
        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription))->assertRedirect();

        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', '!=', $paymentA->id)->count());
    }

    public function test_consume_credit_without_available_credit_returns_error_and_leaves_period_unchanged(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $periodStart = $subscription->current_period_start->format('Y-m-d H:i:s');
        $periodEnd = $subscription->current_period_end->format('Y-m-d H:i:s');

        $response = $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $response->assertRedirect(route('subscriptions.show', $subscription));
        $response->assertSessionHas('error');

        $subscription->refresh();
        $this->assertSame($periodStart, $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame($periodEnd, $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumption_failed')->count());
    }

    public function test_consume_credit_rejects_terminated_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $this->makePaidPayment($subscription, 15000, 1);

        $periodStart = $subscription->current_period_start->format('Y-m-d H:i:s');

        $response = $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $response->assertRedirect(route('subscriptions.show', $subscription));
        $response->assertSessionHas('error');
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame($periodStart, $subscription->fresh()->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_consume_credit_skips_refunded_payment(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $refunded = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $refunded->update(['status' => Payment::STATUS_REFUNDED]);

        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $response = $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $response->assertRedirect(route('subscriptions.show', $subscription));
        $response->assertSessionHas('success');

        $consumption = SubscriptionPaymentConsumption::query()->sole();
        $this->assertSame($paymentB->id, $consumption->payment_id);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $refunded->id)->count());
    }

    public function test_multiple_calls_respect_fifo_across_three_payments(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-02 10:00:00',
        ]);
        $paymentC = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-03 10:00:00',
        ]);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));
        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));
        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $counts = [
            $paymentA->id => SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count(),
            $paymentB->id => SubscriptionPaymentConsumption::query()->where('payment_id', $paymentB->id)->count(),
            $paymentC->id => SubscriptionPaymentConsumption::query()->where('payment_id', $paymentC->id)->count(),
        ];

        $this->assertSame(3, $counts[$paymentA->id]);
        $this->assertSame(0, $counts[$paymentB->id]);
        $this->assertSame(0, $counts[$paymentC->id]);
        $this->assertSame(3, SubscriptionPaymentConsumption::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société HTTP crédit',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation HTTP crédit',
            'subdomain' => 'http-credit-'.uniqid(),
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
