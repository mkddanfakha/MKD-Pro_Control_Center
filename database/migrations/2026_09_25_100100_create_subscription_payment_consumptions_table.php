<?php

use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_NAME = 'subscription_payment_consumptions_subscription_period_unique';

    /**
     * @var list<int>
     */
    private array $unresolvedPaymentIds = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_payment_consumptions', function (Blueprint $table) {
            $table->engine('InnoDB');

            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('consumed_at');
            $table->timestamps();

            $table->unique(
                ['subscription_id', 'period_start', 'period_end'],
                self::UNIQUE_NAME,
            );

            $table->index('payment_id');
            $table->index('subscription_id');
            $table->index('consumed_at');
        });

        $this->backfillPaymentCreditFields();

        if ($this->unresolvedPaymentIds !== []) {
            Log::warning('Task 89 backfill: paiements sans crédit entièrement résolu.', [
                'payment_ids' => $this->unresolvedPaymentIds,
            ]);
        }

        $this->enforcePaymentCreditColumnsNotNullWhenSafe();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_consumptions');
    }

    private function backfillPaymentCreditFields(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $payments = DB::table('payments')
            ->join('subscriptions', 'subscriptions.id', '=', 'payments.subscription_id')
            ->select(
                'payments.id',
                'payments.amount',
                'payments.status',
                'payments.paid_at',
                'payments.renewal_applied_at',
                'subscriptions.amount as subscription_amount',
            )
            ->orderBy('payments.id')
            ->get();

        foreach ($payments as $payment) {
            $this->backfillSinglePayment($payment);
        }
    }

    private function backfillSinglePayment(object $payment): void
    {
        $paymentId = (int) $payment->id;
        $status = (string) $payment->status;
        $subscriptionAmount = (int) $payment->subscription_amount;
        $paymentAmount = (int) $payment->amount;

        if ($status !== Payment::STATUS_PAID) {
            $this->markUnresolved($paymentId, 'status_not_paid');

            return;
        }

        if ($payment->paid_at === null) {
            $this->markUnresolved($paymentId, 'paid_without_paid_at');

            return;
        }

        if ($subscriptionAmount <= 0) {
            $this->markUnresolved($paymentId, 'invalid_subscription_amount');

            return;
        }

        if ($paymentAmount % $subscriptionAmount !== 0) {
            $this->markUnresolved($paymentId, 'amount_not_divisible_by_subscription_amount');

            return;
        }

        $monthsPurchased = (int) ($paymentAmount / $subscriptionAmount);

        if ($monthsPurchased < 1) {
            $this->markUnresolved($paymentId, 'invalid_credit_months_purchased');

            return;
        }

        DB::table('payments')
            ->where('id', $paymentId)
            ->update([
                'monthly_unit_amount' => $subscriptionAmount,
                'credit_months_purchased' => $monthsPurchased,
            ]);

        if ($payment->renewal_applied_at !== null) {
            $this->backfillHistoricalConsumptionFromAudit($paymentId);
        }
    }

    private function backfillHistoricalConsumptionFromAudit(int $paymentId): void
    {
        $audit = DB::table('audit_logs')
            ->where('action', 'payment.renewal_applied')
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $paymentId)
            ->orderBy('id')
            ->first();

        if ($audit === null) {
            $this->markUnresolved($paymentId, 'renewal_applied_without_reliable_audit');

            return;
        }

        $newValues = json_decode((string) $audit->new_values, true);

        if (! is_array($newValues)) {
            $this->markUnresolved($paymentId, 'renewal_audit_new_values_invalid');

            return;
        }

        $subscriptionSnapshot = $newValues['subscription'] ?? null;

        if (! is_array($subscriptionSnapshot)) {
            $this->markUnresolved($paymentId, 'renewal_audit_missing_subscription_snapshot');

            return;
        }

        $periodStart = $subscriptionSnapshot['current_period_start'] ?? null;
        $periodEnd = $subscriptionSnapshot['current_period_end'] ?? null;

        if (! is_string($periodStart) || $periodStart === '' || ! is_string($periodEnd) || $periodEnd === '') {
            $this->markUnresolved($paymentId, 'renewal_audit_missing_period_in_snapshot');

            return;
        }

        $subscriptionId = (int) DB::table('payments')->where('id', $paymentId)->value('subscription_id');

        $consumedAt = $audit->created_at ?? now();

        DB::table('subscription_payment_consumptions')->insert([
            'payment_id' => $paymentId,
            'subscription_id' => $subscriptionId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'consumed_at' => $consumedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function markUnresolved(int $paymentId, string $reason): void
    {
        if (! in_array($paymentId, $this->unresolvedPaymentIds, true)) {
            $this->unresolvedPaymentIds[] = $paymentId;
        }

        Log::info('Task 89 backfill: paiement laissé sans crédit complet.', [
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);
    }

    private function enforcePaymentCreditColumnsNotNullWhenSafe(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $remainingNulls = DB::table('payments')
            ->where(function ($query) {
                $query->whereNull('monthly_unit_amount')
                    ->orWhereNull('credit_months_purchased');
            })
            ->count();

        if ($remainingNulls > 0) {
            Log::info('Task 89: monthly_unit_amount / credit_months_purchased restent nullable.', [
                'payments_with_null_credit_fields' => $remainingNulls,
            ]);

            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY monthly_unit_amount INT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE payments MODIFY credit_months_purchased SMALLINT UNSIGNED NOT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite ne permet pas ALTER COLUMN facilement ; les colonnes restent nullable en tests.
            return;
        }
    }
};
