<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Services\SubscriptionAutomaticCreditRenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SubscriptionAutomaticCreditRenewalDryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
    }

    public function test_dry_run_with_enabled_false_exits_without_simulation(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('désactivé', $output);
        $this->assertStringContainsString('dry-run', strtolower($output));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_dry_run_with_enabled_true_simulates_without_mutation(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $before = $this->captureRenewalState($subscription, $payment);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('1 simulations', $output);

        $subscription->refresh();
        $payment->refresh();
        $this->assertSame($before, $this->captureRenewalState($subscription, $payment));
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
    }

    public function test_dry_run_predicts_fifo_payment_and_matches_real_execution(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $paymentA = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $simulation = app(SubscriptionAutomaticCreditRenewalService::class)
            ->simulateAutomaticRenewal($subscription);

        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_WOULD_CONSUME, $simulation['outcome']);
        $this->assertSame($paymentA->id, $simulation['payment_id']);
        $this->assertSame('2026-11-01 00:00:00', $simulation['simulated_period_start']);
        $this->assertSame('2026-11-30 23:59:59', $simulation['simulated_period_end']);

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        Artisan::call('subscriptions:renew-with-credit');

        $consumption = SubscriptionPaymentConsumption::query()->sole();
        $subscription->refresh();

        $this->assertSame($paymentA->id, $consumption->payment_id);
        $this->assertSame('2026-11-30 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_dry_run_valid_period_subscription_not_evaluated(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-10-10 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);

        $this->assertStringContainsString('0 évalués', Artisan::output());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_dry_run_no_credit_reports_reason_without_error(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        $this->makeSubscription();

        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->assertSame(0, Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]));
        $this->assertStringContainsString('no_consumable_credit=1', Artisan::output());
    }

    public function test_dry_run_grace_period_then_real_run_reactivates(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $this->assertStringContainsString('simulation renouvellement', Artisan::output());

        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_dry_run_suspended_then_real_run_reactivates(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-10 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    public function test_dry_run_terminated_not_in_evaluation_scope(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-12-01 12:00:00');

        $simulation = app(SubscriptionAutomaticCreditRenewalService::class)
            ->simulateAutomaticRenewal($subscription);

        $this->assertFalse($simulation['outcome'] === SubscriptionAutomaticCreditRenewalService::OUTCOME_WOULD_CONSUME);

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $this->assertStringContainsString('0 évalués', Artisan::output());
    }

    public function test_dry_run_three_month_credit_simulates_one_month_only(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);

        $payment->refresh();
        $this->assertSame(3, $payment->remainingCreditMonths());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(2, $payment->fresh()->remainingCreditMonths());
    }

    public function test_dry_run_idempotent_until_real_consumption_changes_state(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());

        Artisan::call('subscriptions:renew-with-credit');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $this->assertStringContainsString('0 simulations', Artisan::output());
    }

    public function test_dry_run_technical_error_logs_dry_run_flag_and_non_zero_exit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        Log::spy();

        $failing = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($failing, 15000, 1);

        $success = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($success, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $realService = app(SubscriptionAutomaticCreditRenewalService::class);
        $mock = Mockery::mock(SubscriptionAutomaticCreditRenewalService::class, [
            app(\App\Services\SubscriptionService::class),
            app(\App\Services\AuditLogService::class),
        ])->makePartial();

        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('querySubscriptionsForRenewalEvaluation')
            ->andReturn($realService->querySubscriptionsForRenewalEvaluation());

        $mock->shouldReceive('simulateAutomaticRenewal')
            ->andReturnUsing(function (Subscription $subscription) use ($failing, $realService) {
                if ($subscription->id === $failing->id) {
                    throw new RuntimeException('Erreur dry-run simulée');
                }

                return $realService->simulateAutomaticRenewal($subscription);
            });

        $this->app->instance(SubscriptionAutomaticCreditRenewalService::class, $mock);

        $exitCode = Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => ($context['dry_run'] ?? null) === true);
    }

    public function test_dry_run_completion_logs_dry_run_true(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        Log::spy();

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);

        Log::shouldHaveReceived('info')
            ->with('subscriptions:renew-with-credit — exécution terminée', \Mockery::on(
                fn (array $context): bool => ($context['dry_run'] ?? null) === true
                    && ($context['simulated'] ?? null) === 1
            ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function captureRenewalState(Subscription $subscription, Payment $payment): array
    {
        return [
            'consumptions' => SubscriptionPaymentConsumption::query()->count(),
            'audits' => AuditLog::query()->where('action', 'subscription.credit_consumed')->count(),
            'payments' => Payment::query()->count(),
            'subscription' => [
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start?->format('Y-m-d H:i:s'),
                'current_period_end' => $subscription->current_period_end?->format('Y-m-d H:i:s'),
                'grace_period_ends_at' => $subscription->grace_period_ends_at?->format('Y-m-d H:i:s'),
                'suspended_at' => $subscription->suspended_at?->format('Y-m-d H:i:s'),
            ],
            'payment' => [
                'amount' => (int) $payment->amount,
                'monthly_unit_amount' => (int) $payment->monthly_unit_amount,
                'credit_months_purchased' => (int) $payment->credit_months_purchased,
                'credit_exhausted_at' => $payment->credit_exhausted_at?->format('Y-m-d H:i:s'),
                'remaining' => $payment->remainingCreditMonths(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $installation = $this->makeInstallation();

        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client Dry 277',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'dry277-'.uniqid(),
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePaidPayment(Subscription $subscription, int $amount, int $months, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => $months,
        ], $overrides));
    }
}
