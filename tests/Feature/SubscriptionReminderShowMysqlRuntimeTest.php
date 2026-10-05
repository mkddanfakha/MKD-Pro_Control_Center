<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * Runtime MySQL de la fiche Rappel d'abonnement — TASK 325.
 *
 * php artisan test --configuration=phpunit.mysql-subscription-reminder-show.xml
 */
class SubscriptionReminderShowMysqlRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_TEST_DATABASE = 'mkdpro_control_subscription_reminder_show_test';

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== self::MYSQL_TEST_DATABASE) {
            $this->markTestSkipped(
                'Test MySQL runtime — lancer : php artisan test --configuration=phpunit.mysql-subscription-reminder-show.xml'
            );
        }

        $this->ensureMysqlTestDatabaseExistsFromEnv();

        parent::setUp();

        Mail::fake();
    }

    public function test_reminder_show_runtime_on_mysql_with_relations_notification_and_audit(): void
    {
        $user = User::factory()->create();
        $client = Client::query()->create([
            'company_name' => 'Runtime MySQL TASK325',
            'contact_name' => 'Contact Rappel',
            'email' => 'task325@example.com',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation TASK325',
            'subdomain' => 'rem-task325-'.uniqid(),
            'status' => 'active',
        ]);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 18000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 54000,
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-10 12:00:00',
        ]);

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-24 00:00:00',
            'detected_at' => '2026-10-24 12:00:00',
            'sent_at' => null,
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        AuditLog::query()->create([
            'action' => 'reminder.runtime_mysql_audit',
            'auditable_type' => $reminder->getMorphClass(),
            'auditable_id' => $reminder->id,
            'result' => 'success',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SubscriptionReminders/Show')
                ->where('reminder.id', $reminder->id)
                ->where('reminder.subscription_id', $subscription->id)
                ->where('reminder.threshold_days', 7)
                ->where('reminder.status', SubscriptionReminder::STATUS_DETECTED)
                ->where('reminder.scheduled_for', '2026-10-24 00:00:00')
                ->where('reminder.detected_at', '2026-10-24 12:00:00')
                ->where('reminder.sent_at', null)
                ->where('subscription.id', $subscription->id)
                ->where('subscription.amount', 18000)
                ->where('installation.id', $installation->id)
                ->where('client.company_name', 'Runtime MySQL TASK325')
                ->where('notification.channel', 'email')
                ->where('notification.amount', 18000)
                ->where('notification.currency', 'XOF')
                ->where('notification.current_period_end', '2026-10-31 23:59:59')
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.amount', 54000)
                ->has('audit_history', 1)
                ->where('audit_history.0.action', 'reminder.runtime_mysql_audit')
                ->has('navigation.reminders_index'));

        $props = $response->original->getData()['page']['props'] ?? [];
        $encoded = json_encode($props, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('terminated_count', $encoded);
        $this->assertStringNotContainsString('database_name', $encoded);

        $queries = collect(DB::getQueryLog())->pluck('query');
        foreach (['subscription_reminders', 'subscriptions', 'installations', 'clients', 'payments', 'audit_logs'] as $fragment) {
            $this->assertTrue(
                $queries->contains(fn (string $q) => str_contains(strtolower($q), $fragment)),
                'Missing query fragment: '.$fragment
            );
        }

        Mail::assertNothingSent();
    }

    public function test_reminder_show_on_mysql_covers_all_threshold_days(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscriptionForThresholdTests();

        $cases = [
            7 => '2026-10-24 00:00:00',
            3 => '2026-10-28 00:00:00',
            1 => '2026-10-30 00:00:00',
            0 => '2026-10-31 00:00:00',
        ];

        foreach ($cases as $threshold => $scheduledFor) {
            $reminder = SubscriptionReminder::query()->create([
                'subscription_id' => $subscription->id,
                'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
                'threshold_days' => $threshold,
                'scheduled_for' => $scheduledFor,
                'detected_at' => $scheduledFor,
                'status' => SubscriptionReminder::STATUS_DETECTED,
            ]);

            $this->actingAs($user)
                ->get(route('subscription-reminders.show', $reminder))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('reminder.threshold_days', $threshold)
                    ->where('reminder.scheduled_for', $scheduledFor)
                    ->where('notification.amount', 18000)
                    ->where('subscription.amount', 18000));
        }
    }

    public function test_reminder_show_on_mysql_supports_detected_sent_and_failed_statuses(): void
    {
        $user = User::factory()->create();
        $clientId = Client::query()->create([
            'company_name' => 'Statuts REM TASK325',
            'contact_name' => 'Contact',
            'status' => 'active',
        ])->id;

        $statusCases = [
            SubscriptionReminder::STATUS_DETECTED => [
                'sent_at' => null,
                'scheduled_for' => '2026-11-01 00:00:00',
                'threshold_days' => 7,
            ],
            SubscriptionReminder::STATUS_SENT => [
                'sent_at' => '2026-11-02 09:30:00',
                'scheduled_for' => '2026-11-03 00:00:00',
                'threshold_days' => 3,
            ],
            SubscriptionReminder::STATUS_FAILED => [
                'sent_at' => null,
                'scheduled_for' => '2026-11-05 00:00:00',
                'threshold_days' => 1,
            ],
        ];

        foreach ($statusCases as $status => $attrs) {
            $installation = Installation::query()->create([
                'client_id' => $clientId,
                'name' => 'Inst '.$status,
                'subdomain' => 'rem-'.$status.'-'.uniqid(),
                'status' => 'active',
            ]);
            $subscription = Subscription::query()->create([
                'installation_id' => $installation->id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_end' => '2026-11-30 23:59:59',
            ]);
            $reminder = SubscriptionReminder::query()->create([
                'subscription_id' => $subscription->id,
                'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
                'threshold_days' => $attrs['threshold_days'],
                'scheduled_for' => $attrs['scheduled_for'],
                'detected_at' => $attrs['scheduled_for'],
                'sent_at' => $attrs['sent_at'],
                'status' => $status,
            ]);

            $this->actingAs($user)
                ->get(route('subscription-reminders.show', $reminder))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('reminder.status', $status)
                    ->where('reminder.sent_at', $attrs['sent_at'])
                    ->has('notification.title'));

            Mail::assertNothingSent();
        }
    }

    public function test_logical_unique_constraint_prevents_duplicate_reminder_on_mysql(): void
    {
        $subscription = $this->makeSubscriptionForThresholdTests();

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-12-01 00:00:00',
            'detected_at' => '2026-12-01 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->expectException(QueryException::class);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-12-01 00:00:00',
            'detected_at' => '2026-12-01 13:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
    }

    public function test_guest_and_forbidden_access_on_mysql(): void
    {
        $reminder = $this->makeMinimalReminder();

        $this->get(route('subscription-reminders.show', $reminder))
            ->assertRedirect(route('login'));

        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertForbidden();
    }

    private function makeSubscriptionForThresholdTests(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Seuils TASK325',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Seuils',
            'subdomain' => 'seuils-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 18000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
    }

    private function makeMinimalReminder(): SubscriptionReminder
    {
        $subscription = $this->makeSubscriptionForThresholdTests();

        return SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-24 00:00:00',
            'detected_at' => '2026-10-24 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
    }

    private function ensureMysqlTestDatabaseExistsFromEnv(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            (string) $username,
            (string) $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $database = self::MYSQL_TEST_DATABASE;
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '``', $database),
        ));
    }
}
