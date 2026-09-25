<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPeriodCoherenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_both_period_dates_null(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => null,
            'period_end' => null,
        ]));

        $response->assertRedirect();
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_store_rejects_period_start_without_period_end(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => null,
        ]));

        $response->assertSessionHasErrors(['period_start', 'period_end']);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_store_rejects_period_end_without_period_start(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => null,
            'period_end' => '2026-10-31 23:59:59',
        ]));

        $response->assertSessionHasErrors(['period_start', 'period_end']);
    }

    public function test_store_accepts_valid_period_start_and_end(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]));

        $response->assertRedirect();
    }

    public function test_store_rejects_period_end_before_period_start(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => '2026-10-31 00:00:00',
            'period_end' => '2026-10-01 00:00:00',
        ]));

        $response->assertSessionHasErrors('period_end');
    }

    public function test_store_accepts_period_matching_subscription_days(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]));

        $response->assertRedirect();
    }

    public function test_store_accepts_period_not_matching_subscription_current_period(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'period_start' => '2026-09-01 00:00:00',
            'period_end' => '2026-09-30 23:59:59',
        ]));

        $response->assertRedirect();
    }

    public function test_update_rejects_partial_period_fields(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => null,
        ]));

        $response->assertSessionHasErrors(['period_start', 'period_end']);
    }

    public function test_update_rejects_period_change_after_renewal_applied_when_period_was_set(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => 1,
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
        $amount = (int) ($attributes['amount'] ?? $subscription->amount);

        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => (int) ($amount / (int) $subscription->amount),
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
