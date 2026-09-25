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

class PaymentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_creation_is_audited(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $payload = [
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'reference' => 'PAY-001',
            'notes' => 'Paiement test',
        ];

        $response = $this->actingAs($user)->post(route('payments.store'), $payload);

        $response->assertRedirect();

        $payment = Payment::query()->where('reference', 'PAY-001')->firstOrFail();

        $log = AuditLog::query()->where('action', 'payment.created')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(Payment::class, $log->auditable_type);
        $this->assertSame($payment->id, $log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertSame(15000, $log->new_values['amount']);
        $this->assertSame(Payment::STATUS_PENDING, $log->new_values['status']);
        $this->assertSame($subscription->id, $log->new_values['subscription_id']);
    }

    public function test_payment_update_is_audited(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
            'reference' => 'REF-OLD',
            'notes' => 'Note A',
        ]);

        $response = $this->actingAs($user)->put(route('payments.update', $payment), [
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'reference' => 'REF-NEW',
            'notes' => 'Note B',
        ]);

        $response->assertRedirect(route('payments.show', $payment));

        $payment->refresh();

        $this->assertSame(15000, $payment->amount);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);

        $log = AuditLog::query()->where('action', 'payment.updated')->sole();

        $this->assertSame(15000, $log->old_values['amount']);
        $this->assertSame(15000, $log->new_values['amount']);
        $this->assertSame(Payment::STATUS_PENDING, $log->old_values['status']);
        $this->assertSame(Payment::STATUS_PAID, $log->new_values['status']);
        $this->assertSame('REF-OLD', $log->old_values['reference']);
        $this->assertSame('REF-NEW', $log->new_values['reference']);
    }

    public function test_payment_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $paymentId = $payment->id;

        $response = $this->actingAs($user)->delete(route('payments.destroy', $payment));

        $response->assertRedirect(route('payments.index'));

        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);

        $log = AuditLog::query()->where('action', 'payment.deleted')->sole();

        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertSame($paymentId, $log->old_values['id']);
    }

    public function test_successful_renewal_is_audited_with_payment_and_subscription_snapshots(): void
    {
        $user = User::factory()->create();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]);

        $response = $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect(route('payments.show', $payment));
        $response->assertSessionHas('success');

        $payment->refresh();
        $subscription->refresh();

        $this->assertNull($payment->renewal_applied_at);
        $this->assertNotNull($payment->credit_exhausted_at);
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));

        $log = AuditLog::query()->where('action', 'payment.renewal_applied')->sole();

        $this->assertSame(Payment::class, $log->auditable_type);
        $this->assertSame($payment->id, $log->auditable_id);
        $this->assertNull($log->old_values['payment']['renewal_applied_at']);
        $this->assertNull($log->new_values['payment']['renewal_applied_at']);
        $this->assertSame('2026-10-31 23:59:59', $log->old_values['subscription']['current_period_end']);
        $this->assertSame('2026-11-30 23:59:59', $log->new_values['subscription']['current_period_end']);
    }

    public function test_renewal_does_not_modify_installation_status(): void
    {
        $user = User::factory()->create();

        $installation = Installation::query()->create([
            'client_id' => $this->makeClient()->id,
            'name' => 'Installation Renouvellement',
            'subdomain' => 'renew-'.uniqid(),
            'status' => 'active',
        ]);

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]);

        $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $this->assertSame('active', $installation->fresh()->status);
    }

    public function test_double_renewal_does_not_create_second_renewal_audit(): void
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
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]);

        $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_applied')->count());

        $response = $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect(route('payments.show', $payment));
        $response->assertSessionHas('error');

        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
    }

    public function test_failed_renewal_creates_renewal_failed_audit_without_technical_message(): void
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
            'status' => Payment::STATUS_PENDING,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $subscriptionBefore = $subscription->fresh();
        $paymentBefore = $payment->fresh();

        $response = $this->actingAs($user)->post(route('payments.renew-subscription', $payment));

        $response->assertRedirect(route('payments.show', $payment));
        $response->assertSessionHas('error');

        $this->assertSame(0, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_failed')->count());

        $log = AuditLog::query()->where('action', 'payment.renewal_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame(Payment::class, $log->auditable_type);
        $this->assertSame($payment->id, $log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertSame('Le renouvellement de l’abonnement a échoué.', $log->error_message);

        $encoded = json_encode([
            $log->old_values,
            $log->new_values,
            $log->error_message,
        ], JSON_THROW_ON_ERROR);

        $technicalMessage = 'Seul un paiement au statut payé permet le renouvellement.';
        $this->assertStringNotContainsString($technicalMessage, $encoded);

        $payment->refresh();
        $subscription->refresh();

        $this->assertSame($paymentBefore->status, $payment->status);
        $this->assertNull($payment->renewal_applied_at);
        $this->assertSame($subscriptionBefore->current_period_start?->format('Y-m-d H:i:s'), $subscription->current_period_start?->format('Y-m-d H:i:s'));
        $this->assertSame($subscriptionBefore->current_period_end?->format('Y-m-d H:i:s'), $subscription->current_period_end?->format('Y-m-d H:i:s'));
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
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        return Installation::query()->create([
            'client_id' => $this->makeClient()->id,
            'name' => 'Installation Test',
            'subdomain' => 'pay-'.uniqid(),
            'status' => 'active',
        ]);
    }

    private function makeClient(): Client
    {
        return Client::query()->create([
            'company_name' => 'Client Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);
    }
}
