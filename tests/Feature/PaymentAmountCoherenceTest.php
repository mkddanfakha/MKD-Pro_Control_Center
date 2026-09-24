<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAmountCoherenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_amount_and_currency_matching_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 16000]);

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => 16000,
            'currency' => 'XOF',
        ]));

        $response->assertRedirect();
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_store_rejects_amount_different_from_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 16000]);

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => 15000,
        ]));

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_store_rejects_currency_different_from_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'currency' => 'EUR',
        ]));

        $response->assertSessionHasErrors('currency');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_update_accepts_matching_amount_and_currency(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'reference' => 'REF-UPDATED',
        ]));

        $response->assertRedirect(route('payments.show', $payment));
        $this->assertSame('REF-UPDATED', $payment->fresh()->reference);
    }

    public function test_update_rejects_amount_different_from_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 16000]);
        $payment = $this->makePayment($subscription);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'amount' => 15000,
        ]));

        $response->assertSessionHasErrors('amount');
    }

    public function test_update_rejects_currency_different_from_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'currency' => 'EUR',
        ]));

        $response->assertSessionHasErrors('currency');
    }

    public function test_update_rejects_amount_change_after_renewal_applied(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'renewal_applied_at' => '2026-10-02 12:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'amount' => 16000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertSessionHasErrors('amount');
        $this->assertSame(15000, $payment->fresh()->amount);
    }

    public function test_update_rejects_currency_change_after_renewal_applied(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'renewal_applied_at' => '2026-10-02 12:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertSessionHasErrors('currency');
    }

    public function test_update_rejects_subscription_change_after_renewal_applied(): void
    {
        $user = User::factory()->create();
        $subscriptionA = $this->makeSubscription();
        $subscriptionB = $this->makeSubscription();

        $payment = Payment::query()->create([
            'subscription_id' => $subscriptionA->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'renewal_applied_at' => '2026-10-02 12:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscriptionB, [
            'subscription_id' => $subscriptionB->id,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertSessionHasErrors('subscription_id');
    }

    public function test_update_rejects_period_change_after_renewal_applied(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'renewal_applied_at' => '2026-10-02 12:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
        ]));

        $response->assertSessionHasErrors(['period_start', 'period_end']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(Subscription $subscription, array $overrides = []): array
    {
        return array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PENDING,
            'payment_method' => 'wave',
            'reference' => 'REF-'.uniqid(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(Subscription $subscription, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PENDING,
        ], $attributes));
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
}
