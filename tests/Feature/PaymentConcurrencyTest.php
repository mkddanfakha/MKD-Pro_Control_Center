<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlTestingConnection;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    private ?int $clientId = null;

    private ?int $installationId = null;

    private ?int $subscriptionId = null;

    private ?int $paymentId = null;

    protected function tearDown(): void
    {
        if (MysqlTestingConnection::applyFromProjectEnv()) {
            if ($this->paymentId !== null) {
                DB::table('subscription_payment_consumptions')
                    ->where('payment_id', $this->paymentId)
                    ->delete();

                Payment::query()->whereKey($this->paymentId)->delete();
            }

            if ($this->subscriptionId !== null) {
                Subscription::query()->whereKey($this->subscriptionId)->delete();
            }

            if ($this->installationId !== null) {
                Installation::query()->whereKey($this->installationId)->delete();
            }

            if ($this->clientId !== null) {
                Client::query()->whereKey($this->clientId)->delete();
            }
        }

        MysqlTestingConnection::restorePhpunitTestingConnection();

        parent::tearDown();
    }

    public function test_concurrent_renew_from_payment_on_same_payment_allows_only_one_success(): void
    {
        if (! MysqlTestingConnection::applyFromProjectEnv()) {
            $this->markTestSkipped('MySQL (.env) requis pour le test de concurrence InnoDB.');
        }

        $client = Client::query()->create([
            'company_name' => 'Client Concurrence',
            'contact_name' => 'Contact Concurrence',
            'status' => 'active',
        ]);
        $this->clientId = $client->id;

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Concurrence',
            'subdomain' => 'concurrency-'.uniqid(),
            'status' => 'active',
        ]);
        $this->installationId = $installation->id;

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-09-01 00:00:00',
            'current_period_end' => '2026-09-30 23:59:59',
        ]);
        $this->subscriptionId = $subscription->id;

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-09-15 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
            'period_start' => null,
            'period_end' => null,
            'renewal_applied_at' => null,
        ]);
        $this->paymentId = $payment->id;

        $expectedNextPeriodStart = '2026-10-01 00:00:00';
        $expectedNextPeriodEnd = '2026-10-31 23:59:59';

        $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-payment-renewal-'.uniqid();
        mkdir($syncDir);

        try {
            $workerScript = base_path('tests/concurrency/payment_renewal_worker.php');

            $processA = new Process([
                PHP_BINARY,
                $workerScript,
                (string) $payment->id,
                $syncDir,
                'A',
            ], base_path());
            $processA->setTimeout(60);

            $processB = new Process([
                PHP_BINARY,
                $workerScript,
                (string) $payment->id,
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

            $this->assertSame(['REJECTED', 'SUCCESS'], $statuses, sprintf(
                'Résultats workers inattendus. A=%s B=%s',
                json_encode($resultA),
                json_encode($resultB),
            ));

            DB::connection('mysql')->disconnect();

            $paymentAfter = Payment::query()->findOrFail($payment->id);
            $subscriptionAfter = Subscription::query()->findOrFail($subscription->id);

            $this->assertNotNull($paymentAfter->credit_exhausted_at, 'credit_exhausted_at doit être renseigné après consommation unique.');
            $this->assertSame(1, DB::table('subscription_payment_consumptions')->where('payment_id', $payment->id)->count());

            $this->assertSame(
                $expectedNextPeriodStart,
                $subscriptionAfter->current_period_start?->format('Y-m-d H:i:s'),
                'La période ne doit avancer qu\'une seule fois (début).',
            );

            $this->assertSame(
                $expectedNextPeriodEnd,
                $subscriptionAfter->current_period_end?->format('Y-m-d H:i:s'),
                'La période ne doit avancer qu\'une seule fois (fin).',
            );

            $this->assertSame(Subscription::STATUS_ACTIVE, $subscriptionAfter->status);
            $this->assertNull($subscriptionAfter->grace_period_ends_at);
            $this->assertNull($subscriptionAfter->suspended_at);
        } finally {
            $this->removeDirectory($syncDir);
        }
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
     * @return array{status: string, worker?: string, message?: string}
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
