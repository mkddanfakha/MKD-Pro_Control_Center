<?php

namespace Tests\Feature;

use App\Mail\SubscriptionReminderMail;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SubscriptionReminderProcessingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.timezone', 'UTC');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_reminders_disabled_performs_no_writes(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');

        $this->assertSame(0, SubscriptionReminder::query()->count());
        Mail::assertNothingSent();
    }

    public function test_reminders_enabled_notifications_disabled_persists_without_send(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $subscription = $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');

        $this->assertSame(1, SubscriptionReminder::query()->count());
        $reminder = SubscriptionReminder::query()->first();
        $this->assertSame(SubscriptionReminder::STATUS_DETECTED, $reminder->status);
        $this->assertNull($reminder->sent_at);
        Mail::assertNothingSent();
        $this->assertSame($subscription->id, $reminder->subscription_id);
    }

    public function test_email_channel_sends_and_marks_sent(): void
    {
        $this->enableRemindersAndEmailNotifications();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');

        $reminder = SubscriptionReminder::query()->first();
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->status);
        $this->assertNotNull($reminder->sent_at);
        Mail::assertSent(SubscriptionReminderMail::class, 1);
    }

    public function test_missing_admin_email_marks_failed_without_mail(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', null);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');

        $reminder = SubscriptionReminder::query()->first();
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $reminder->status);
        $this->assertNull($reminder->sent_at);
        Mail::assertNothingSent();
    }

    public function test_dry_run_reports_without_mutation(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'ops@example.test');
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders', ['--dry-run' => true]);

        $this->assertSame(0, SubscriptionReminder::query()->count());
        Mail::assertNothingSent();
        $this->assertStringContainsString('Dry-run', Artisan::output());
    }

    public function test_json_output_contains_required_fields(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        foreach (['enabled', 'evaluated', 'newly_detected', 'already_recorded', 'sent', 'failed', 'ignored', 'errors', 'duration_ms'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertTrue($payload['enabled']);
        $this->assertSame(1, $payload['newly_detected']);
    }

    public function test_second_run_is_idempotent_without_extra_mail(): void
    {
        $this->enableRemindersAndEmailNotifications();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');
        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $second = json_decode(Artisan::output(), true);

        $this->assertSame(0, $second['newly_detected']);
        $this->assertSame(1, $second['already_recorded']);
        $this->assertSame(0, $second['sent']);
        Mail::assertSent(SubscriptionReminderMail::class, 1);
    }

    public function test_already_sent_reminder_is_not_resent(): void
    {
        $this->enableRemindersAndEmailNotifications();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $subscription = $this->makeSubscriptionAtThreshold(7);
        $service = app(SubscriptionReminderService::class);
        $record = $service->recordDetectedReminder($subscription, 7);
        $service->markAsSent($record['reminder']);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $payload['newly_detected']);
        $this->assertSame(1, $payload['already_recorded']);
        $this->assertSame(1, $payload['ignored']);
        Mail::assertNothingSent();
    }

    public function test_failed_reminder_is_not_retried(): void
    {
        $this->enableRemindersAndEmailNotifications();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $subscription = $this->makeSubscriptionAtThreshold(7);
        $service = app(SubscriptionReminderService::class);
        $record = $service->recordDetectedReminder($subscription, 7);
        $service->markAsFailed($record['reminder'], 'test');

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $payload['sent']);
        $this->assertSame(1, $payload['ignored']);
        Mail::assertNothingSent();
    }

    public function test_installation_filter_limits_scope(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $first = $this->makeSubscriptionAtThreshold(7, 'inst-a');
        $second = $this->makeSubscriptionAtThreshold(7, 'inst-b');

        Artisan::call('subscriptions:process-reminders', [
            '--installation' => $first->installation_id,
        ]);

        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertSame($first->id, SubscriptionReminder::query()->value('subscription_id'));
        $this->assertNotSame($second->installation_id, $first->installation_id);
    }

    public function test_subscription_filter_limits_scope(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $target = $this->makeSubscriptionAtThreshold(7);
        $this->makeSubscriptionAtThreshold(7, 'other');

        Artisan::call('subscriptions:process-reminders', [
            '--subscription' => $target->id,
        ]);

        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertSame($target->id, SubscriptionReminder::query()->value('subscription_id'));
    }

    #[DataProvider('thresholdProvider')]
    public function test_threshold_creates_single_reminder(int $threshold, string $now, string $periodEnd): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow($now);
        $this->makeSubscriptionWithPeriodEnd($periodEnd);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['newly_detected']);
        $this->assertSame($threshold, SubscriptionReminder::query()->value('threshold_days'));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function thresholdProvider(): array
    {
        return [
            'seuil_7' => [7, '2026-10-24 12:00:00', '2026-10-31 23:59:59'],
            'seuil_3' => [3, '2026-10-28 12:00:00', '2026-10-31 23:59:59'],
            'seuil_1' => [1, '2026-10-30 12:00:00', '2026-10-31 23:59:59'],
            'seuil_0' => [0, '2026-10-31 12:00:00', '2026-10-31 23:59:59'],
        ];
    }

    public function test_ineligible_subscription_creates_no_reminder(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionWithPeriodEnd('2026-10-31 23:59:59', Subscription::STATUS_SUSPENDED);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $payload['newly_detected']);
        $this->assertSame(0, SubscriptionReminder::query()->count());
    }

    public function test_isolated_persistence_error_increments_errors(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        $real = app(SubscriptionReminderService::class);
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('recordDetectedReminder')
            ->once()
            ->andThrow(new RuntimeException('Erreur technique simulée'));
        $this->app->instance(SubscriptionReminderService::class, $mock);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['errors']);
        $this->assertSame(0, SubscriptionReminder::query()->count());
    }

    public function test_multiple_subscriptions_summary(): void
    {
        $this->enableRemindersAndEmailNotifications();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7, 'a');
        $this->makeSubscriptionAtThreshold(7, 'b');

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(2, $payload['evaluated']);
        $this->assertSame(2, $payload['newly_detected']);
        $this->assertSame(2, $payload['sent']);
        $this->assertSame(2, SubscriptionReminder::query()->where('status', SubscriptionReminder::STATUS_SENT)->count());
        Mail::assertSent(SubscriptionReminderMail::class, 2);
    }

    public function test_text_summary_lines_are_present(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');
        $output = Artisan::output();

        $this->assertStringContainsString('Traitement des rappels', $output);
        $this->assertStringContainsString('Évalués', $output);
        $this->assertStringContainsString('Nouveaux rappels', $output);
    }

    public function test_prepared_channel_marks_sent_without_email(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['sent']);
        Mail::assertNothingSent();
    }

    private function enableRemindersAndEmailNotifications(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'ops@mkd-pro.test');
    }

    private function makeSubscriptionAtThreshold(int $threshold, string $suffix = 'default'): Subscription
    {
        $periodEnd = '2026-10-31 23:59:59';
        $now = match ($threshold) {
            7 => '2026-10-24 12:00:00',
            3 => '2026-10-28 12:00:00',
            1 => '2026-10-30 12:00:00',
            0 => '2026-10-31 12:00:00',
            default => '2026-10-24 12:00:00',
        };

        if (Carbon::getTestNow() === null) {
            Carbon::setTestNow($now);
        }

        return $this->makeSubscriptionWithPeriodEnd($periodEnd, Subscription::STATUS_ACTIVE, $suffix);
    }

    private function makeSubscriptionWithPeriodEnd(
        string $periodEnd,
        string $status = Subscription::STATUS_ACTIVE,
        string $suffix = 'default',
    ): Subscription {
        $client = Client::query()->create([
            'company_name' => 'Client Process '.$suffix,
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation '.$suffix,
            'subdomain' => 'proc287-'.$suffix.'-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => $status,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => $periodEnd,
        ]);
    }
}
