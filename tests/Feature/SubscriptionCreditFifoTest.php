<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlTestingConnection;
use Tests\TestCase;

class SubscriptionCreditFifoTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    /** @var list<int> */
    private array $mysqlPaymentIds = [];

    private ?int $mysqlSubscriptionId = null;

    private ?int $mysqlInstallationId = null;

    private ?int $mysqlClientId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    protected function tearDown(): void
    {
        if (MysqlTestingConnection::applyFromProjectEnv()) {
            if ($this->mysqlPaymentIds !== []) {
                DB::table('subscription_payment_consumptions')
                    ->whereIn('payment_id', $this->mysqlPaymentIds)
                    ->delete();

                Payment::query()->whereIn('id', $this->mysqlPaymentIds)->delete();
            }

            if ($this->mysqlSubscriptionId !== null) {
                Subscription::query()->whereKey($this->mysqlSubscriptionId)->delete();
            }

            if ($this->mysqlInstallationId !== null) {
                Installation::query()->whereKey($this->mysqlInstallationId)->delete();
            }

            if ($this->mysqlClientId !== null) {
                Client::query()->whereKey($this->mysqlClientId)->delete();
            }
        }

        MysqlTestingConnection::restorePhpunitTestingConnection();

        parent::tearDown();
    }

    public function test_fifo_selects_earlier_paid_at_before_later_payment(): void
    {
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $consumption = $this->service->consumeNextCreditForSubscription($subscription);

        $this->assertSame($paymentA->id, $consumption->payment_id);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentB->id)->count());
    }

    public function test_fifo_breaks_ties_on_payment_id_when_paid_at_is_equal(): void
    {
        $subscription = $this->makeSubscription();

        $paymentFirstCreated = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-10 12:00:00',
        ]);
        $paymentSecondCreated = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-10 12:00:00',
        ]);

        $expectedPaymentId = min($paymentFirstCreated->id, $paymentSecondCreated->id);
        $otherPaymentId = max($paymentFirstCreated->id, $paymentSecondCreated->id);

        $consumption = $this->service->consumeNextCreditForSubscription($subscription);

        $this->assertSame($expectedPaymentId, $consumption->payment_id);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $otherPaymentId)->count());
    }

    public function test_fifo_respects_historical_rates_after_subscription_price_change(): void
    {
        $subscription = $this->makeSubscription(['amount' => 15000]);

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);

        $subscription->update(['amount' => 20000]);
        $subscription->refresh();

        $paymentB = $this->makePaidPayment($subscription, 60000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $this->assertSame(15000, (int) $paymentA->fresh()->monthly_unit_amount);
        $this->assertSame(20000, (int) $paymentB->fresh()->monthly_unit_amount);

        $first = $this->service->consumeNextCreditForSubscription($subscription);
        $second = $this->service->consumeNextCreditForSubscription($subscription->fresh());

        $this->assertSame($paymentA->id, $first->payment_id);
        $this->assertSame($paymentA->id, $second->payment_id);
        $this->assertSame(2, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentB->id)->count());
        $this->assertSame(4, $paymentA->fresh()->remainingCreditMonths());
        $this->assertSame(3, $paymentB->fresh()->remainingCreditMonths());
    }

    public function test_fifo_keeps_using_partially_consumed_payment_before_later_payment(): void
    {
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $this->service->consumeCreditFromPayment($paymentA);
        $this->service->consumeCreditFromPayment($paymentA->fresh());

        $third = $this->service->consumeNextCreditForSubscription($subscription->fresh());

        $this->assertSame($paymentA->id, $third->payment_id);
        $this->assertSame(3, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentB->id)->count());
    }

    public function test_fifo_moves_to_next_payment_when_first_is_exhausted(): void
    {
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $this->service->consumeNextCreditForSubscription($subscription);

        $consumption = $this->service->consumeNextCreditForSubscription($subscription->fresh());

        $this->assertSame($paymentB->id, $consumption->payment_id);
        $this->assertNotNull($paymentA->fresh()->credit_exhausted_at);
    }

    public function test_fifo_skips_refunded_payment_and_uses_next_eligible(): void
    {
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $paymentB = $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $paymentA->update(['status' => Payment::STATUS_REFUNDED]);

        $consumption = $this->service->consumeNextCreditForSubscription($subscription->fresh());

        $this->assertSame($paymentB->id, $consumption->payment_id);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('payment_id', $paymentA->id)->count());
    }

    public function test_consume_next_credit_throws_when_no_eligible_payment_exists(): void
    {
        $subscription = $this->makeSubscription();

        $periodStart = $subscription->current_period_start->format('Y-m-d H:i:s');
        $periodEnd = $subscription->current_period_end->format('Y-m-d H:i:s');

        try {
            $this->service->consumeNextCreditForSubscription($subscription);
            $this->fail('Expected SubscriptionRenewalException was not thrown.');
        } catch (SubscriptionRenewalException $exception) {
            $this->assertSame('Aucun crédit disponible pour cet abonnement.', $exception->getMessage());
        }

        $subscription->refresh();
        $this->assertSame($periodStart, $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame($periodEnd, $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_consume_next_credit_rejects_terminated_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $this->makePaidPayment($subscription, 15000, 1);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('Impossible de renouveler un abonnement terminé.');

        $this->service->consumeNextCreditForSubscription($subscription);
    }

    public function test_concurrent_consume_next_credit_creates_distinct_periods_without_duplication(): void
    {
        if (! MysqlTestingConnection::applyFromProjectEnv()) {
            $this->markTestSkipped('MySQL (.env) requis pour le test de concurrence InnoDB.');
        }

        $client = Client::query()->create([
            'company_name' => 'Client FIFO Concurrence',
            'contact_name' => 'Contact FIFO',
            'status' => 'active',
        ]);
        $this->mysqlClientId = $client->id;

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation FIFO',
            'subdomain' => 'fifo-concurrency-'.uniqid(),
            'status' => 'active',
        ]);
        $this->mysqlInstallationId = $installation->id;

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-09-01 00:00:00',
            'current_period_end' => '2026-09-30 23:59:59',
        ]);
        $this->mysqlSubscriptionId = $subscription->id;

        $paymentA = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 90000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-01 10:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 6,
        ]);
        $paymentB = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-05 10:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ]);
        $this->mysqlPaymentIds = [$paymentA->id, $paymentB->id];

        $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-subscription-fifo-'.uniqid();
        mkdir($syncDir);

        try {
            $workerScript = base_path('tests/concurrency/subscription_credit_fifo_worker.php');

            $processA = new Process([
                PHP_BINARY,
                $workerScript,
                (string) $subscription->id,
                $syncDir,
                'A',
            ], base_path());
            $processA->setTimeout(60);

            $processB = new Process([
                PHP_BINARY,
                $workerScript,
                (string) $subscription->id,
                $syncDir,
                'B',
            ], base_path());
            $processB->setTimeout(60);

            $processA->start();
            $processB->start();

            $this->waitForWorkerReadyFiles($syncDir, ['A', 'B'], 30);

            file_put_contents($syncDir.DIRECTORY_SEPARATOR.'go.signal', (string) microtime(true));

            $this->assertSame(0, $processA->wait(), $processA->getErrorOutput());
            $this->assertSame(0, $processB->wait(), $processB->getErrorOutput());

            $resultA = $this->decodeWorkerResult($processA->getOutput(), 'A');
            $resultB = $this->decodeWorkerResult($processB->getOutput(), 'B');

            $statuses = [$resultA['status'], $resultB['status']];
            sort($statuses);

            $this->assertSame(['SUCCESS', 'SUCCESS'], $statuses);

            DB::connection('mysql')->disconnect();

            $consumptions = DB::table('subscription_payment_consumptions')
                ->where('subscription_id', $subscription->id)
                ->orderBy('period_start')
                ->get();

            $this->assertCount(2, $consumptions);

            $periodStarts = $consumptions->pluck('period_start')->map(fn ($value) => (string) $value)->all();
            $this->assertSame($periodStarts, array_values(array_unique($periodStarts)));

            $this->assertSame('2026-10-01 00:00:00', $periodStarts[0]);
            $this->assertSame('2026-11-01 00:00:00', $periodStarts[1]);

            $paymentIdsUsed = $consumptions->pluck('payment_id')->unique()->values()->all();
            $this->assertContains($paymentA->id, $paymentIdsUsed);
        } finally {
            $this->removeDirectory($syncDir);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société FIFO',
            'contact_name' => 'Contact FIFO',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation FIFO',
            'subdomain' => 'fifo-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePaidPayment(Subscription $subscription, int $amount, int $months, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ], $attributes));
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

    /**
     * @return array{status: string, worker?: string, message?: string, payment_id?: int, period_start?: string}
     */
    private function decodeWorkerResult(string $output, string $workerLabel): array
    {
        $output = trim($output);

        if ($output === '') {
            $this->fail('Sortie vide pour le worker '.$workerLabel);
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! isset($decoded['status'])) {
            $this->fail('JSON worker invalide pour '.$workerLabel.': '.$output);
        }

        return $decoded;
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
