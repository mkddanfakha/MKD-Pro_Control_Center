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

class PaymentDestroyConsumptionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_succeeds_when_payment_has_no_consumption(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription);
        $paymentId = $payment->id;

        $response = $this->actingAs($user)->delete(route('payments.destroy', $payment));

        $response->assertRedirect(route('payments.index'));
        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.deleted')->count());
    }

    public function test_destroy_rejects_payment_with_one_consumption(): void
    {
        $user = User::factory()->create();
        [$subscription, $payment] = $this->makePaidPaymentWithConsumption();

        $response = $this->actingAs($user)->delete(route('payments.destroy', $payment));

        $response->assertSessionHasErrors([
            'payment' => 'Ce paiement ne peut pas être supprimé car son crédit a déjà été consommé.',
        ]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.deleted')->count());
    }

    public function test_destroy_rejects_payment_with_multiple_consumptions(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, [
            'amount' => 45000,
            'credit_months_purchased' => 3,
        ]);

        foreach ([
            ['2026-10-01 00:00:00', '2026-10-31 23:59:59', '2026-10-02 10:00:00'],
            ['2026-11-01 00:00:00', '2026-11-30 23:59:59', '2026-11-02 10:00:00'],
            ['2026-12-01 00:00:00', '2026-12-31 23:59:59', '2026-12-02 10:00:00'],
        ] as [$periodStart, $periodEnd, $consumedAt]) {
            SubscriptionPaymentConsumption::query()->create([
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'consumed_at' => $consumedAt,
            ]);
        }

        $response = $this->actingAs($user)->delete(route('payments.destroy', $payment));

        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertSame(3, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.deleted')->count());
    }

    public function test_destroy_allows_paid_payment_without_consumption(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ]);

        $paymentId = $payment->id;

        $response = $this->actingAs($user)->delete(route('payments.destroy', $payment));

        $response->assertRedirect(route('payments.index'));
        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentId)->count());
    }

    /**
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{0: Subscription, 1: Payment}
     */
    private function makePaidPaymentWithConsumption(array $paymentOverrides = []): array
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, $paymentOverrides);

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
     * @param  array<string, mixed>  $attributes
     */
    private function makePaidPayment(Subscription $subscription, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Destroy Guard',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Destroy Guard',
            'subdomain' => 'destroy-guard-'.uniqid(),
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
