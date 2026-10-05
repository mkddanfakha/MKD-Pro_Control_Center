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
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlTestingConnection;
use Tests\TestCase;

/**
 * Politique métier du renouvellement automatique par crédit — Task 273.
 */
class SubscriptionAutomaticCreditRenewalBusinessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionAutomaticCreditRenewalService $automaticRenewal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->automaticRenewal = app(SubscriptionAutomaticCreditRenewalService::class);
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
    }

    public function test_period_still_valid_at_exact_period_end_boundary(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $atEnd = Carbon::parse('2026-10-31 23:59:59');
        $assessment = $this->automaticRenewal->assessEligibility($subscription, $atEnd);

        $this->assertFalse($assessment['eligible']);
        $this->assertSame(
            SubscriptionAutomaticCreditRenewalService::REASON_PERIOD_NOT_EXPIRED,
            $assessment['reason'],
        );
    }

    public function test_period_expired_one_second_after_end_is_eligible_with_credit(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $afterEnd = Carbon::parse('2026-11-01 00:00:00');
        $assessment = $this->automaticRenewal->assessEligibility($subscription, $afterEnd);

        $this->assertTrue($assessment['eligible']);
    }

    public function test_expired_period_without_credit_is_not_eligible(): void
    {
        $subscription = $this->makeSubscription();

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-11-05 12:00:00'),
        );

        $this->assertFalse($assessment['eligible']);
        $this->assertSame(
            SubscriptionAutomaticCreditRenewalService::REASON_NO_CONSUMABLE_CREDIT,
            $assessment['reason'],
        );
    }

    public function test_when_enabled_grace_period_consumption_reactivates_active_and_clears_grace(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $result = $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_CONSUMED, $result['outcome']);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_when_enabled_suspended_subscription_with_credit_reactivates_via_engine(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-10 12:00:00');

        $result = $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_CONSUMED, $result['outcome']);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    public function test_late_execution_consumes_only_one_calendar_month_not_catch_up(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 45000, 3);

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $subscription->refresh();
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(2, Payment::query()->first()->remainingCreditMonths());
    }

    public function test_command_when_disabled_does_not_consume(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $exitCode = Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_command_when_enabled_consumes_at_most_one_month_per_subscription(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscriptionA = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($subscriptionA, 75000, 5);

        $subscriptionB = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($subscriptionB, 30000, 2);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit');

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscriptionA->id)->count());
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscriptionB->id)->count());
        $this->assertSame(4, Payment::query()->where('subscription_id', $subscriptionA->id)->first()->remainingCreditMonths());
    }

    public function test_lifecycle_scheduler_still_does_not_consume_after_policy_formalized(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
    }

    public function test_when_enabled_single_audit_per_automatic_consumption(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
    }

    public function test_concurrent_automatic_renewal_allows_only_one_consumption(): void
    {
        if (! MysqlTestingConnection::applyFromProjectEnv()) {
            $this->markTestSkipped('MySQL (.env) requis pour le test de concurrence InnoDB.');
        }

        $subscription = $this->makeSubscriptionMysql();
        $installationId = $subscription->installation_id;
        $clientId = Installation::query()->findOrFail($installationId)->client_id;
        $this->makePaidPaymentMysql($subscription, 15000, 1);

        $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-auto-renewal-'.uniqid();
        mkdir($syncDir);

        try {
            $workerScript = base_path('tests/concurrency/automatic_credit_renewal_worker.php');

            $processA = new Process([PHP_BINARY, $workerScript, (string) $subscription->id, $syncDir, 'A'], base_path());
            $processB = new Process([PHP_BINARY, $workerScript, (string) $subscription->id, $syncDir, 'B'], base_path());
            $processA->setTimeout(60);
            $processB->setTimeout(60);

            $processA->start();
            $processB->start();

            $this->waitForWorkerReadyFiles($syncDir, ['A', 'B'], 30);
            file_put_contents($syncDir.DIRECTORY_SEPARATOR.'go.signal', (string) microtime(true));

            $this->assertSame(0, $processA->wait(), $processA->getErrorOutput());
            $this->assertSame(0, $processB->wait(), $processB->getErrorOutput());

            $resultA = json_decode(trim($processA->getOutput()), true);
            $resultB = json_decode(trim($processB->getOutput()), true);

            $statuses = [$resultA['status'], $resultB['status']];
            sort($statuses);
            $this->assertSame(['REJECTED', 'SUCCESS'], $statuses);

            DB::connection('mysql')->disconnect();

            $this->assertSame(1, DB::table('subscription_payment_consumptions')->where('subscription_id', $subscription->id)->count());
        } finally {
            $this->removeDirectory($syncDir);
            SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->delete();
            Payment::query()->where('subscription_id', $subscription->id)->delete();
            Subscription::query()->whereKey($subscription->id)->delete();
            Installation::query()->whereKey($installationId)->delete();
            Client::query()->whereKey($clientId)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        MysqlTestingConnection::restorePhpunitTestingConnection();
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
            'company_name' => 'Client Policy 273',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'pol273-'.uniqid(),
            'status' => 'active',
        ]);
    }

    private function makeSubscriptionMysql(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Concurrence Auto',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'autoconc-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2020-09-01 00:00:00',
            'current_period_start' => '2020-09-01 00:00:00',
            'current_period_end' => '2020-09-30 23:59:59',
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

    private function makePaidPaymentMysql(Subscription $subscription, int $amount, int $months): Payment
    {
        return $this->makePaidPayment($subscription, $amount, $months);
    }

    /**
     * @param  list<string>  $workerIds
     */
    private function waitForWorkerReadyFiles(string $syncDir, array $workerIds, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $allReady = true;

            foreach ($workerIds as $workerId) {
                if (! is_file($syncDir.DIRECTORY_SEPARATOR.'worker_'.$workerId.'.ready')) {
                    $allReady = false;
                    break;
                }
            }

            if ($allReady) {
                return;
            }

            usleep(5000);
        }

        $this->fail('Les workers n\'ont pas signalé leur préparation avant le timeout.');
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
