<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentStatusCoherenceSqlTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_PAID_AT = '2026-10-01 12:00:00';

    public function test_pending_with_null_paid_at_is_accepted(): void
    {
        $subscription = $this->makeSubscription();

        $payment = $this->createPayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => null,
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_PENDING,
            'paid_at' => null,
        ]);
    }

    public function test_pending_with_paid_at_is_rejected(): void
    {
        $subscription = $this->makeSubscription();

        $this->expectException(QueryException::class);

        $this->createPayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => self::SAMPLE_PAID_AT,
        ]);
    }

    public function test_failed_with_null_paid_at_is_accepted(): void
    {
        $subscription = $this->makeSubscription();

        $payment = $this->createPayment($subscription, [
            'status' => Payment::STATUS_FAILED,
            'paid_at' => null,
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_FAILED,
        ]);
    }

    public function test_failed_with_paid_at_is_rejected(): void
    {
        $subscription = $this->makeSubscription();

        $this->expectException(QueryException::class);

        $this->createPayment($subscription, [
            'status' => Payment::STATUS_FAILED,
            'paid_at' => self::SAMPLE_PAID_AT,
        ]);
    }

    public function test_paid_with_paid_at_is_accepted(): void
    {
        $subscription = $this->makeSubscription();

        $payment = $this->createPayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => self::SAMPLE_PAID_AT,
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_PAID,
        ]);
    }

    public function test_paid_with_null_paid_at_is_rejected(): void
    {
        $subscription = $this->makeSubscription();

        $this->expectException(QueryException::class);

        $this->createPayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => null,
        ]);
    }

    public function test_refunded_with_paid_at_is_accepted(): void
    {
        $subscription = $this->makeSubscription();

        $payment = $this->createPayment($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => self::SAMPLE_PAID_AT,
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_REFUNDED,
        ]);
    }

    public function test_refunded_with_null_paid_at_is_rejected(): void
    {
        $subscription = $this->makeSubscription();

        $this->expectException(QueryException::class);

        $this->createPayment($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => null,
        ]);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $subscription = $this->makeSubscription();

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => 'unknown_status',
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPayment(Subscription $subscription, array $attributes): Payment
    {
        $amount = (int) ($attributes['amount'] ?? 15000);

        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => 'XOF',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => (int) ($amount / (int) $subscription->amount),
        ], $attributes));
    }

    private function makeSubscription(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client SQL Test',
            'contact_name' => 'Contact SQL Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation SQL Test',
            'subdomain' => 'sql-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }
}
