<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCreditHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_calculates_credit_for_one_month(): void
    {
        $this->assertStoreCreditFields(15000, 1);
    }

    public function test_store_calculates_credit_for_three_months(): void
    {
        $this->assertStoreCreditFields(45000, 3);
    }

    public function test_store_calculates_credit_for_six_months(): void
    {
        $this->assertStoreCreditFields(90000, 6);
    }

    public function test_store_calculates_credit_for_twelve_months(): void
    {
        $this->assertStoreCreditFields(180000, 12);
    }

    private function assertStoreCreditFields(int $amount, int $expectedMonths): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => $amount,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]));

        $response->assertRedirect();

        $payment = Payment::query()->orderByDesc('id')->first();
        $this->assertNotNull($payment);

        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame($expectedMonths, (int) $payment->credit_months_purchased);
        $this->assertSame($amount, (int) $payment->amount);
    }

    public function test_store_rejects_non_divisible_amount_for_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $countBefore = Payment::query()->count();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => 16000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]));

        $response->assertSessionHasErrors('amount');
        $this->assertSame($countBefore, Payment::query()->count());
    }

    public function test_store_rejects_fractional_month_amount(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $countBefore = Payment::query()->count();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => 22500,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]));

        $response->assertSessionHasErrors('amount');
        $this->assertSame($countBefore, Payment::query()->count());
    }

    public function test_store_rejects_zero_amount(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => 0,
        ]));

        $response->assertSessionHasErrors('amount');
    }

    public function test_store_rejects_client_supplied_credit_fields(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $countBefore = Payment::query()->count();

        $response = $this->actingAs($user)->post(route('payments.store'), $this->validPayload($subscription, [
            'amount' => 15000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => 1,
            'credit_months_purchased' => 180000,
        ]));

        $response->assertSessionHasErrors(['monthly_unit_amount', 'credit_months_purchased']);
        $this->assertSame($countBefore, Payment::query()->count());
    }

    public function test_update_recalculates_credit_when_no_consumption_exists(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'amount' => 45000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]));

        $response->assertRedirect(route('payments.show', $payment));

        $payment->refresh();

        $this->assertSame(45000, (int) $payment->amount);
        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame(3, (int) $payment->credit_months_purchased);
    }

    public function test_update_rejects_financial_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'amount' => 15000,
        ]));

        $response->assertSessionHasErrors('amount');
        $this->assertSame(45000, $payment->fresh()->amount);
    }

    public function test_update_rejects_subscription_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();
        $otherSubscription = $this->makeSubscription(['amount' => 15000]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'subscription_id' => $otherSubscription->id,
        ]));

        $response->assertSessionHasErrors('subscription_id');
        $this->assertSame($subscription->id, $payment->fresh()->subscription_id);
    }

    public function test_update_rejects_currency_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'currency' => 'EUR',
        ]));

        $response->assertSessionHasErrors('currency');
        $this->assertSame('XOF', $payment->fresh()->currency);
    }

    public function test_update_rejects_period_start_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'period_start' => '2026-10-15 00:00:00',
        ]));

        $response->assertSessionHasErrors('period_start');
        $this->assertSame('2026-10-01 00:00:00', $payment->fresh()->period_start->format('Y-m-d H:i:s'));
    }

    public function test_update_rejects_period_end_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'period_end' => '2026-10-20 23:59:59',
        ]));

        $response->assertSessionHasErrors('period_end');
        $this->assertSame('2026-10-31 23:59:59', $payment->fresh()->period_end->format('Y-m-d H:i:s'));
    }

    public function test_update_rejects_status_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => null,
        ]));

        $response->assertSessionHasErrors('status');
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_update_rejects_refunded_status_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertSessionHasErrors('status');
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_update_rejects_multiple_protected_fields_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'amount' => 15000,
            'currency' => 'EUR',
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'status' => Payment::STATUS_PAID,
        ]));

        $response->assertSessionHasErrors(['amount', 'currency', 'period_start', 'period_end']);

        $payment->refresh();

        $this->assertSame(45000, (int) $payment->amount);
        $this->assertSame('XOF', $payment->currency);
        $this->assertSame('2026-10-01 00:00:00', $payment->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $payment->period_end->format('Y-m-d H:i:s'));
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
    }

    public function test_update_allows_metadata_change_after_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption([
            'reference' => 'REF-BEFORE',
            'notes' => 'Notes initiales',
            'payment_method' => 'wave',
            'due_at' => '2026-09-28 08:00:00',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->baselinePayloadForConsumedPayment($subscription, $payment, [
            'reference' => 'REF-AFTER-CONSO',
            'notes' => 'Notes mises à jour après consommation',
            'payment_method' => 'cash',
            'due_at' => '2026-09-30 09:30:00',
            'paid_at' => '2026-10-02 16:45:00',
        ]));

        $response->assertRedirect(route('payments.show', $payment));

        $payment->refresh();

        $this->assertSame('REF-AFTER-CONSO', $payment->reference);
        $this->assertSame('Notes mises à jour après consommation', $payment->notes);
        $this->assertSame('cash', $payment->payment_method);
        $this->assertSame('2026-09-30 09:30:00', $payment->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 16:45:00', $payment->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame(45000, (int) $payment->amount);
        $this->assertSame('XOF', $payment->currency);
        $this->assertSame('2026-10-01 00:00:00', $payment->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $payment->period_end->format('Y-m-d H:i:s'));
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
    }

    public function test_update_allows_financial_change_when_paid_without_consumption(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), $this->validPayload($subscription, [
            'amount' => 45000,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
        ]));

        $response->assertRedirect(route('payments.show', $payment));

        $payment->refresh();

        $this->assertSame(45000, (int) $payment->amount);
        $this->assertSame(3, (int) $payment->credit_months_purchased);
    }

    /**
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{0: Subscription, 1: Payment}
     */
    private function makePaidPaymentWithConsumption(array $paymentOverrides = []): array
    {
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $payment = Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
            'reference' => 'REF-CONSUMED',
        ], $paymentOverrides));

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => '2026-11-01 10:00:00',
        ]);

        return [$subscription, $payment];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baselinePayloadForConsumedPayment(Subscription $subscription, Payment $payment, array $overrides = []): array
    {
        return $this->validPayload($subscription, array_merge([
            'subscription_id' => $payment->subscription_id,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'period_start' => $payment->period_start?->format('Y-m-d H:i:s'),
            'period_end' => $payment->period_end?->format('Y-m-d H:i:s'),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'payment_method' => $payment->payment_method ?? 'wave',
            'due_at' => $payment->due_at?->format('Y-m-d H:i:s'),
        ], $overrides));
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
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Crédit HTTP',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Crédit HTTP',
            'subdomain' => 'credit-http-'.uniqid(),
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
