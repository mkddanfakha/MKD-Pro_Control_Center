<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlTestingConnection;
use Tests\TestCase;

class SubscriptionReminderConcurrencyTest extends TestCase
{
    private ?int $clientId = null;

    private ?int $installationId = null;

    private ?int $subscriptionId = null;

    protected function tearDown(): void
    {
        if (MysqlTestingConnection::applyFromProjectEnv()) {
            if ($this->subscriptionId !== null) {
                DB::table('subscription_reminders')->where('subscription_id', $this->subscriptionId)->delete();
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

    public function test_concurrent_record_detected_reminder_creates_single_row(): void
    {
        if (! MysqlTestingConnection::applyFromProjectEnv()) {
            $this->markTestSkipped('MySQL (.env) requis pour le test de concurrence InnoDB (SQLite ne reproduit pas fidèlement les courses concurrentes).');
        }

        $client = Client::query()->create([
            'company_name' => 'Client Reminder Concurrence',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $this->clientId = $client->id;

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Concurrence Reminder',
            'subdomain' => 'rem-concurrency-'.uniqid(),
            'status' => 'active',
        ]);
        $this->installationId = $installation->id;

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $this->subscriptionId = $subscription->id;

        $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-reminder-'.uniqid();
        mkdir($syncDir);

        try {
            $workerScript = base_path('tests/concurrency/subscription_reminder_worker.php');

            $processA = new Process([
                PHP_BINARY,
                $workerScript,
                (string) $subscription->id,
                '7',
                $syncDir,
                'A',
            ], base_path());
            $processA->setTimeout(60);

            $processB = new Process([
                PHP_BINARY,
                $workerScript,
                (string) $subscription->id,
                '7',
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

            $this->assertSame('SUCCESS', $resultA['status']);
            $this->assertSame('SUCCESS', $resultB['status']);

            $createdCount = (int) $resultA['created'] + (int) $resultB['created'];
            $this->assertSame(1, $createdCount, 'Exactement un worker doit créer le rappel.');

            DB::connection('mysql')->disconnect();

            $this->assertSame(1, SubscriptionReminder::query()->where('subscription_id', $subscription->id)->count());
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
     * @return array{status: string, worker?: string, created?: bool, reminder_id?: int}
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
