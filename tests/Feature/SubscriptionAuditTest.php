<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_creation_is_audited(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $payload = [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'notes' => 'Abonnement initial',
        ];

        $response = $this->actingAs($user)->post(route('subscriptions.store'), $payload);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $log = AuditLog::query()->where('action', 'subscription.created')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(Subscription::class, $log->auditable_type);
        $this->assertSame($subscription->id, $log->auditable_id);
        $this->assertSame('success', $log->result);
        $this->assertNull($log->old_values);
        $this->assertSame($installation->id, $log->new_values['installation_id']);
        $this->assertSame(15000, $log->new_values['amount']);
        $this->assertSame('XOF', $log->new_values['currency']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $log->new_values['status']);
        $this->assertSame('2026-10-01 00:00:00', $log->new_values['starts_at']);
        $this->assertSame('2026-10-01 00:00:00', $log->new_values['current_period_start']);
        $this->assertSame('2026-10-31 23:59:59', $log->new_values['current_period_end']);
    }

    public function test_subscription_update_is_audited(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'notes' => 'Note A',
        ]);

        $response = $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 20000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
            'notes' => 'Note B',
        ]);

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();

        $this->assertSame(20000, $subscription->amount);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->status);

        $log = AuditLog::query()->where('action', 'subscription.updated')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($subscription->id, $log->auditable_id);
        $this->assertSame(15000, $log->old_values['amount']);
        $this->assertSame(20000, $log->new_values['amount']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $log->new_values['status']);
        $this->assertSame('Note A', $log->old_values['notes']);
        $this->assertSame('Note B', $log->new_values['notes']);
    }

    public function test_subscription_status_change_does_not_modify_installation(): void
    {
        $user = User::factory()->create();

        $installation = Installation::query()->create([
            'client_id' => $this->makeClient()->id,
            'name' => 'Installation Active',
            'subdomain' => 'active-'.uniqid(),
            'status' => 'active',
        ]);

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();
        $installation->refresh();

        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->status);
        $this->assertSame('active', $installation->status);

        $log = AuditLog::query()->where('action', 'subscription.updated')->sole();

        $this->assertSame(Subscription::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $log->new_values['status']);
    }

    public function test_destroy_subscription_with_unconsumed_payment_is_blocked_by_foreign_key(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $payment = $this->makePaidPayment($subscription);

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());

        $this->assertSubscriptionDestroyIsBlockedByPaymentsForeignKey($user, $subscription, $installation);
    }

    public function test_destroy_subscription_with_consumed_payment_is_blocked_by_foreign_key(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $payment = $this->makePaidPayment($subscription);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => '2026-11-01 10:00:00',
        ]);

        $this->assertSubscriptionDestroyIsBlockedByPaymentsForeignKey($user, $subscription, $installation);
    }

    public function test_destroy_terminated_subscription_with_payment_is_blocked_by_foreign_key(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $this->makePaidPayment($subscription);

        $this->assertSubscriptionDestroyIsBlockedByPaymentsForeignKey($user, $subscription, $installation);
    }

    public function test_subscription_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $subscriptionId = $subscription->id;

        $response = $this->actingAs($user)->delete(route('subscriptions.destroy', $subscription));

        $response->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseMissing('subscriptions', ['id' => $subscriptionId]);

        $log = AuditLog::query()->where('action', 'subscription.deleted')->sole();

        $this->assertSame('success', $log->result);
        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertSame($subscriptionId, $log->old_values['id']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $log->old_values['status']);
    }

    public function test_subscription_index_and_show_do_not_create_audit_logs(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->get(route('subscriptions.index'))->assertOk();
        $this->actingAs($user)->get(route('subscriptions.show', $subscription))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_unauthenticated_users_cannot_create_subscriptions(): void
    {
        $installation = $this->makeInstallation();

        $response = $this->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response->assertRedirect(route('login'));

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    private function makeClient(): Client
    {
        return Client::query()->create([
            'company_name' => 'Client Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);
    }

    private function makeInstallation(): Installation
    {
        return Installation::query()->create([
            'client_id' => $this->makeClient()->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);
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

    private function assertSubscriptionDestroyIsBlockedByPaymentsForeignKey(
        User $user,
        Subscription $subscription,
        Installation $installation,
    ): void {
        $subscriptionId = $subscription->id;
        $installationId = $installation->id;
        $paymentCount = Payment::query()->where('subscription_id', $subscriptionId)->count();
        $consumptionCount = SubscriptionPaymentConsumption::query()->where('subscription_id', $subscriptionId)->count();

        $this->assertGreaterThan(0, $paymentCount);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->delete(route('subscriptions.destroy', $subscription));
            $this->fail('Expected subscription deletion to be blocked by payments foreign key (QueryException).');
        } catch (QueryException $exception) {
            $this->assertNotSame('', trim($exception->getMessage()));
        }

        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId]);
        $this->assertDatabaseHas('installations', ['id' => $installationId]);
        $this->assertSame($paymentCount, Payment::query()->where('subscription_id', $subscriptionId)->count());
        $this->assertSame(
            $consumptionCount,
            SubscriptionPaymentConsumption::query()->where('subscription_id', $subscriptionId)->count(),
        );
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.deleted')->count());
    }
}
