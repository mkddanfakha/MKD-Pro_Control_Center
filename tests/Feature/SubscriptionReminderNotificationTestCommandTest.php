<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderNotificationTestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');
        Config::set('app.timezone', 'UTC');
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_disabled_returns_success_without_mutation(): void
    {
        $reminder = $this->makeReminder();

        $before = $reminder->fresh()->toArray();

        $exitCode = Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('disabled', strtolower($output));
        $this->assertSame($before, $reminder->fresh()->toArray());
        $this->assertStringContainsString('Aucune notification externe', $output);
    }

    public function test_enabled_marks_detected_reminder_as_sent(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $reminder = $this->makeReminder();

        $exitCode = Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('sent', strtolower($output));
        $this->assertStringContainsString('central_admin', $output);
        $this->assertStringContainsString('Échéance d’abonnement dans 3 jours', $output);
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->sent_at);
    }

    public function test_second_command_call_returns_already_sent(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $reminder = $this->makeReminder();

        Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);
        $sentAt = $reminder->fresh()->sent_at?->format('Y-m-d H:i:s');

        Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('already_sent', $output);
        $this->assertSame($sentAt, $reminder->fresh()->sent_at?->format('Y-m-d H:i:s'));
    }

    public function test_failed_reminder_returns_already_failed(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_FAILED);

        Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);

        $this->assertStringContainsString('already_failed', Artisan::output());
    }

    public function test_json_output_when_enabled(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $reminder = $this->makeReminder();

        Artisan::call('subscriptions:reminder-notification-test', [
            'reminder' => $reminder->id,
            '--json' => true,
        ]);

        preg_match('/\{.*\}/s', Artisan::output(), $matches);
        $decoded = json_decode($matches[0] ?? '', true);

        $this->assertSame($reminder->id, $decoded['reminder_id']);
        $this->assertFalse($decoded['external_notification_sent']);
        $this->assertSame('sent', $decoded['send_result']['status']);
        $this->assertSame('sent', $decoded['reminder_status']);
    }

    public function test_missing_reminder_returns_failure(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $exitCode = Artisan::call('subscriptions:reminder-notification-test', ['reminder' => 999999]);

        $this->assertSame(1, $exitCode);
    }

    public function test_invalid_id_returns_failure(): void
    {
        $exitCode = Artisan::call('subscriptions:reminder-notification-test', ['reminder' => 0]);

        $this->assertSame(1, $exitCode);
    }

    public function test_sent_reminder_status_and_sent_at_unchanged(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_SENT, '2026-10-24 08:00:00');

        Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);

        $fresh = $reminder->fresh();
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-24 08:00:00', $fresh->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_no_payment_or_consumption_side_effects(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);

        $subscription = $this->makeSubscription();
        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $reminder = $this->makeReminderForSubscription($subscription, 3);

        Artisan::call('subscriptions:reminder-notification-test', ['reminder' => $reminder->id]);

        $this->assertSame(1, Payment::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame(
            0,
            SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count(),
        );
    }

    private function makeReminder(
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        return $this->makeReminderForSubscription($this->makeSubscription(), 3, $status, $sentAt);
    }

    private function makeReminderForSubscription(
        Subscription $subscription,
        int $threshold,
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        $service = app(SubscriptionReminderService::class);
        $scheduledFor = $service->resolveScheduledFor($subscription, $threshold);

        return SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => $threshold,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 12:00:00',
            'sent_at' => $sentAt,
            'status' => $status,
        ]);
    }

    private function makeSubscription(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Notif Test',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Boutique Test',
            'subdomain' => 'notif284-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
    }
}
