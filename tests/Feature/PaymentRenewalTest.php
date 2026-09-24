<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
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
        $this->assertNotNull($payment->fresh()->renewal_applied_at);
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
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ], $attributes);

        if (in_array($data['status'], [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
            $data['paid_at'] = null;
        }

        return Payment::query()->create($data);
    }
}
