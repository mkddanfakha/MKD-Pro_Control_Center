<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionPaymentConsumptionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_table_has_credit_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'monthly_unit_amount'));
        $this->assertTrue(Schema::hasColumn('payments', 'credit_months_purchased'));
        $this->assertTrue(Schema::hasColumn('payments', 'credit_exhausted_at'));
    }

    public function test_subscription_payment_consumptions_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('subscription_payment_consumptions'));
    }

    public function test_foreign_keys_exist_on_subscription_payment_consumptions(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription);

        $consumptionId = DB::table('subscription_payment_consumptions')->insertGetId([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'consumed_at' => '2026-10-02 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('subscription_payment_consumptions', ['id' => $consumptionId]);

        $this->expectException(QueryException::class);
        Payment::query()->whereKey($payment->id)->delete();
    }

    public function test_unique_subscription_period_rejects_duplicate_period_even_with_different_payment(): void
    {
        $subscription = $this->makeSubscription();
        $paymentA = $this->makePayment($subscription);
        $paymentB = $this->makePayment($subscription);

        DB::table('subscription_payment_consumptions')->insert([
            'payment_id' => $paymentA->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'consumed_at' => '2026-10-02 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('subscription_payment_consumptions')->insert([
            'payment_id' => $paymentB->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'consumed_at' => '2026-10-03 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_same_payment_can_have_multiple_consumptions_for_different_periods(): void
    {
        $subscription = $this->makeSubscription();
        $payment = $this->makePayment($subscription);

        DB::table('subscription_payment_consumptions')->insert([
            [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => '2026-10-01 00:00:00',
                'period_end' => '2026-10-31 23:59:59',
                'consumed_at' => '2026-10-02 12:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => '2026-11-01 00:00:00',
                'period_end' => '2026-11-30 23:59:59',
                'consumed_at' => '2026-11-02 12:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'period_start' => '2026-12-01 00:00:00',
                'period_end' => '2026-12-31 23:59:59',
                'consumed_at' => '2026-12-02 12:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(3, DB::table('subscription_payment_consumptions')->where('payment_id', $payment->id)->count());
    }

    private function makeClient(): Client
    {
        return Client::query()->create([
            'company_name' => 'Client Schema',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
    }

    private function makeSubscription(): Subscription
    {
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Schema',
            'subdomain' => 'schema-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-09-01 00:00:00',
            'current_period_end' => '2026-09-30 23:59:59',
        ]);
    }

    private function makePayment(Subscription $subscription): Payment
    {
        return Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-15 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);
    }
}
