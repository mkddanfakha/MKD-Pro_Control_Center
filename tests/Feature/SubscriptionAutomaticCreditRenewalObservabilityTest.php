<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Payment;
use App\Models\Product;
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

class SubscriptionAutomaticCreditRenewalObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
    }

    public function test_disabled_run_exits_zero_without_side_effects(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);
        $paymentsBefore = Payment::query()->count();

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('désactivé', $output);
        $this->assertStringContainsString('0 renouvelé', $output);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_enabled_successful_renewal_advances_period_and_decrements_credit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->assertSame(0, Artisan::call('subscriptions:renew-with-credit'));

        $subscription->refresh();
        $payment->refresh();

        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(0, $payment->remainingCreditMonths());
    }

    public function test_cli_summary_includes_evaluated_renewed_and_duration(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');
        $output = Artisan::output();

        $this->assertStringContainsString('1 évalués', $output);
        $this->assertStringContainsString('1 renouvelés', $output);
        $this->assertStringContainsString('durée', $output);
    }

    public function test_batch_run_summary_distinguishes_renewed_skipped_and_not_scanned_valid_period(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $renewed = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($renewed, 15000, 1);

        $noCredit = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);

        $stillValid = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($stillValid, 15000, 1);
        $stillValid->update([
            'current_period_end' => '2026-12-31 23:59:59',
        ]);

        $suspended = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);
        $this->makePaidPayment($suspended, 15000, 1);

        Carbon::setTestNow('2026-11-10 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('3 évalués', $output);
        $this->assertStringContainsString('2 renouvelés', $output);
        $this->assertStringContainsString('1 ignorés', $output);
        $this->assertStringContainsString('no_consumable_credit=1', $output);
        $this->assertSame(2, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_no_credit_is_not_technical_error_exit_code_zero(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        $this->makeSubscription();

        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->assertSame(0, Artisan::call('subscriptions:renew-with-credit'));
        $this->assertStringContainsString('0 erreur(s) technique(s)', Artisan::output());
    }

    public function test_valid_period_subscription_not_evaluated_is_not_error(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-10-10 12:00:00');

        $this->assertSame(0, Artisan::call('subscriptions:renew-with-credit'));
        $this->assertStringContainsString('0 évalués', Artisan::output());
    }

    public function test_terminated_subscription_not_in_scope_is_not_error(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-12-01 12:00:00');

        $this->assertSame(0, Artisan::call('subscriptions:renew-with-credit'));
        $this->assertStringContainsString('0 évalués', Artisan::output());
    }

    public function test_technical_error_is_logged_and_returns_non_zero_exit_while_others_continue(): void
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

        $mock->shouldReceive('attemptAutomaticRenewal')
            ->andReturnUsing(function (Subscription $subscription) use ($failing, $realService) {
                if ($subscription->id === $failing->id) {
                    throw new RuntimeException('Erreur technique simulée');
                }

                return $realService->attemptAutomaticRenewal($subscription);
            });

        $this->app->instance(SubscriptionAutomaticCreditRenewalService::class, $mock);

        $exitCode = Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($failing): bool {
                return str_contains($message, 'erreur technique')
                    && ($context['subscription_id'] ?? null) === $failing->id;
            });
    }

    public function test_successful_automatic_renewal_emits_single_credit_consumed_audit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
        $log = AuditLog::query()->where('action', 'subscription.credit_consumed')->sole();
        $this->assertSame('automatic_credit_renewal', $log->new_values['renewal_trigger']);
    }

    public function test_no_payment_created_and_payment_amounts_unchanged(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 30000, 2);

        $amountBefore = (int) $payment->amount;
        $unitBefore = (int) $payment->monthly_unit_amount;
        $monthsBefore = (int) $payment->credit_months_purchased;

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, Payment::query()->count());
        $payment->refresh();
        $this->assertSame($amountBefore, (int) $payment->amount);
        $this->assertSame($unitBefore, (int) $payment->monthly_unit_amount);
        $this->assertSame($monthsBefore, (int) $payment->credit_months_purchased);
    }

    public function test_subscription_amount_and_offer_version_unchanged_after_renewal(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $offerVersion = $this->makeOfferVersion();
        $subscription = $this->makeSubscription([
            'amount' => 15000,
            'offer_version_id' => $offerVersion->id,
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $offerVersion->refresh();

        $this->assertSame(15000, (int) $subscription->amount);
        $this->assertSame($offerVersion->id, $subscription->offer_version_id);
    }

    public function test_traceability_from_consumption_payment_and_audit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $consumption = SubscriptionPaymentConsumption::query()->sole();
        $audit = AuditLog::query()->where('action', 'subscription.credit_consumed')->sole();

        $this->assertSame($payment->id, $consumption->payment_id);
        $this->assertNotNull($consumption->consumed_at);
        $this->assertSame('2026-11-01 00:00:00', $consumption->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('automatic_credit_renewal', $audit->new_values['renewal_trigger']);
        $this->assertSame($payment->id, $audit->new_values['consumption']['payment_id']);
    }

    public function test_grace_period_renewal_observable_in_cli_and_status(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertStringContainsString('1 renouvelés', Artisan::output());
    }

    public function test_suspended_renewal_observable_in_cli_and_status(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-10 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertStringContainsString('1 renouvelés', Artisan::output());
    }

    public function test_three_month_credit_only_one_consumption_per_run(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(2, $payment->fresh()->remainingCreditMonths());
    }

    public function test_second_immediate_run_does_not_double_consume(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 30000, 2);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');
        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('0 renouvelés', Artisan::output());
    }

    public function test_completed_run_writes_structured_info_log(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        Log::spy();

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        Log::shouldHaveReceived('info')
            ->with('subscriptions:renew-with-credit — exécution terminée', \Mockery::on(
                fn (array $context): bool => ($context['renewed'] ?? null) === 1
            ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        Mockery::close();
        parent::tearDown();
    }

    private function makeOfferVersion(): OfferVersion
    {
        $product = Product::query()->create([
            'code' => 'P-OBS-'.uniqid(),
            'name' => 'Produit obs',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'O-OBS-'.uniqid(),
            'name' => 'Offre obs',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        return OfferVersion::query()->create([
            'offer_id' => $offer->id,
            'version' => 'v-obs-'.uniqid(),
            'code' => 'V-OBS-'.uniqid(),
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'status' => OfferVersion::STATUS_ACTIVE,
            'description' => 'Offre test observabilité',
            'inclusions' => [],
            'limitations' => [],
            'exclusions' => [],
        ]);
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
            'company_name' => 'Client Obs 276',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'obs276-'.uniqid(),
            'status' => 'active',
        ]);
    }

    private function makePaidPayment(Subscription $subscription, int $amount, int $months): Payment
    {
        return Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => $months,
        ]);
    }
}
