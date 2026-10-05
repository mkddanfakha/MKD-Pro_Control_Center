<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlTestingConnection;
use Tests\TestCase;

class SubscriptionReminderNotificationEmailConcurrencyTest extends TestCase
{
    private ?int $clientId = null;

    private ?int $installationId = null;

    private ?int $subscriptionId = null;

    private ?int $reminderId = null;

    protected function tearDown(): void
    {
        if (MysqlTestingConnection::applyFromProjectEnv()) {
            if ($this->reminderId !== null) {
                DB::table('subscription_reminders')->where('id', $this->reminderId)->delete();
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

    public function test_concurrent_email_send_results_in_single_sent_state(): void
    {
        if (! MysqlTestingConnection::applyFromProjectEnv()) {
            $this->markTestSkipped('MySQL (.env) requis pour le test de concurrence InnoDB.');
        }

        $client = Client::query()->create([
            'company_name' => 'Client Email Concurrence',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $this->clientId = $client->id;

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Email Concurrence',
            'subdomain' => 'email-conc-'.uniqid(),
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

        $service = app(SubscriptionReminderService::class);
        $scheduledFor = $service->resolveScheduledFor($subscription, 7);

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
        $this->reminderId = $reminder->id;

        $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-reminder-email-'.uniqid();
        mkdir($syncDir);

        try {
            $workerScript = base_path('tests/concurrency/subscription_reminder_email_notification_worker.php');

            $processA = new Process([PHP_BINARY, $workerScript, (string) $reminder->id, $syncDir, 'A'], base_path());
            $processB = new Process([PHP_BINARY, $workerScript, (string) $reminder->id, $syncDir, 'B'], base_path());
            $processA->setTimeout(60);
            $processB->setTimeout(60);

            $processA->start();
            $processB->start();

            $this->waitForWorkerReadyFiles($syncDir, ['A', 'B'], 30);

            file_put_contents($syncDir.DIRECTORY_SEPARATOR.'go.signal', (string) microtime(true));

            $this->assertSame(0, $processA->wait(), $processA->getErrorOutput());
            $this->assertSame(0, $processB->wait(), $processB->getErrorOutput());

            $resultA = $this->decodeWorkerResult($processA->getOutput(), 'A');
            $resultB = $this->decodeWorkerResult($processB->getOutput(), 'B');

            $sentTransitions = (int) ($resultA['send_status'] === SubscriptionReminderSendResult::STATUS_SENT)
                + (int) ($resultB['send_status'] === SubscriptionReminderSendResult::STATUS_SENT);

            $this->assertSame(1, $sentTransitions);

            DB::connection('mysql')->disconnect();

            $fresh = SubscriptionReminder::query()->findOrFail($reminder->id);
            $this->assertSame(SubscriptionReminder::STATUS_SENT, $fresh->status);
            $this->assertNotNull($fresh->sent_at);
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
     * @return array{status: string, send_status?: string}
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
