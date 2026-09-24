<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentStatusCoherenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_paid_without_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => null,
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_store_pending_with_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_store_failed_with_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_FAILED,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_store_refunded_without_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => null,
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_store_pending_without_paid_at_succeeds(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PENDING,
        ]));

        $response->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->paid_at);
    }

    public function test_store_failed_without_paid_at_succeeds(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_FAILED,
        ]));

        $response->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNull($payment->paid_at);
    }

    public function test_store_paid_with_paid_at_succeeds(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 14:30:00',
        ]));

        $response->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('2026-10-15 14:30:00', $payment->paid_at->format('Y-m-d H:i:s'));
    }

    public function test_store_refunded_with_paid_at_succeeds(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => '2026-10-16 09:00:00',
        ]));

        $response->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_update_paid_without_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => null,
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.updated')->count());
    }

    public function test_update_pending_with_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 10:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => '2026-09-01 10:00:00',
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.updated')->count());
    }

    public function test_update_failed_with_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_FAILED,
            'paid_at' => '2026-09-01 10:00:00',
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.updated')->count());
    }

    public function test_update_refunded_without_paid_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 10:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => null,
        ]));

        $response->assertSessionHasErrors('paid_at');
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.updated')->count());
    }

    public function test_update_from_coherent_pending_to_coherent_paid_succeeds(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-11-01 08:00:00',
        ]));

        $response->assertRedirect(route('payments.show', $payment));
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('2026-11-01 08:00:00', $payment->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.updated')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(Subscription $subscription, array $overrides = []): array
    {
        return array_merge([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'payment_method' => 'wave',
            'reference' => 'REF-'.uniqid(),
        ], $overrides);
    }

    private function makeSubscription(): Subscription
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

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(Subscription $subscription, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
        ], $attributes));
    }
}
