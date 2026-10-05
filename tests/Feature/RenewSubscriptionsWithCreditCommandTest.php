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
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RenewSubscriptionsWithCreditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
    }

    public function test_command_when_disabled_does_not_modify_subscriptions_or_consume_credit(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('désactivé', $output);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $subscription->refresh();
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_command_when_enabled_consumes_one_month_for_expired_subscription_with_credit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('1 renouvelés', Artisan::output());
    }

    public function test_command_does_not_scan_subscription_with_valid_period(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-10-15 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('0 évalués', Artisan::output());
    }

    public function test_command_skips_expired_subscription_without_credit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $this->makeSubscription();

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('1 ignorés', Artisan::output());
    }

    public function test_command_renews_grace_period_subscription_to_active(): void
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
        $this->assertNull($subscription->grace_period_ends_at);
    }

    public function test_command_renews_suspended_subscription_to_active(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-10 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    public function test_command_does_not_process_terminated_subscription(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $subscription = Subscription::query()->first();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-12-01 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('0 évalués', Artisan::output());
    }

    public function test_command_consumes_only_one_month_when_payment_has_three_months_credit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $payment->refresh();
        $this->assertSame(2, $payment->remainingCreditMonths());
    }

    public function test_immediate_second_command_run_does_not_consume_second_month(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 30000, 2);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');
        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_command_processes_multiple_eligible_subscriptions_independently(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subA = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($subA, 15000, 1);

        $subB = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($subB, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(2, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('2 renouvelés', Artisan::output());
    }

    public function test_technical_error_on_one_subscription_allows_others_to_continue(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $failing = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($failing, 15000, 1);

        $success = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($success, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $calls = 0;
        $realService = app(SubscriptionAutomaticCreditRenewalService::class);

        $mock = Mockery::mock(SubscriptionAutomaticCreditRenewalService::class, [
            app(\App\Services\SubscriptionService::class),
            app(\App\Services\AuditLogService::class),
        ])->makePartial();

        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('querySubscriptionsForRenewalEvaluation')
            ->andReturn($realService->querySubscriptionsForRenewalEvaluation());

        $mock->shouldReceive('attemptAutomaticRenewal')
            ->andReturnUsing(function (Subscription $subscription) use (&$calls, $failing, $realService) {
                $calls++;

                if ($subscription->id === $failing->id) {
                    throw new RuntimeException('Erreur technique simulée');
                }

                return $realService->attemptAutomaticRenewal($subscription);
            });

        $this->app->instance(SubscriptionAutomaticCreditRenewalService::class, $mock);

        $exitCode = Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertStringContainsString('1 erreur', Artisan::output());
    }

    public function test_automatic_renewal_via_command_records_expected_audit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $log = AuditLog::query()->where('action', 'subscription.credit_consumed')->sole();
        $this->assertSame('automatic_credit_renewal', $log->new_values['renewal_trigger']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        Mockery::close();
        parent::tearDown();
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
            'company_name' => 'Client CMD 274',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'cmd274-'.uniqid(),
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
