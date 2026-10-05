<?php

namespace Tests\Feature;

use App\Mail\SubscriptionReminderMail;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\EmailSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderNotificationSender;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionReminderNotificationEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionReminderNotificationSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'ops@mkd-pro.test');
        Config::set('app.timezone', 'UTC');
        $this->sender = app(SubscriptionReminderNotificationSender::class);
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_detected_email_channel_marks_sent_and_sends_once(): void
    {
        $reminder = $this->makeReminder(3);

        $result = $this->sender->send($reminder);
        $fresh = $reminder->fresh();

        $this->assertSame(SubscriptionReminderSendResult::STATUS_SENT, $result->status);
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        Mail::assertSent(SubscriptionReminderMail::class, 1);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo('ops@mkd-pro.test'));
    }

    public function test_second_send_is_already_sent_with_single_email(): void
    {
        $reminder = $this->makeReminder(7);

        $this->sender->send($reminder);
        $sentAt = $reminder->fresh()->sent_at?->format('Y-m-d H:i:s');

        $result = $this->sender->send($reminder->fresh());

        $this->assertSame(SubscriptionReminderSendResult::STATUS_ALREADY_SENT, $result->status);
        $this->assertSame($sentAt, $reminder->fresh()->sent_at?->format('Y-m-d H:i:s'));
        Mail::assertSent(SubscriptionReminderMail::class, 1);
    }

    public function test_missing_admin_email_marks_failed_without_mail(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', null);

        $reminder = $this->makeReminder(7);
        $result = $this->sender->send($reminder);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_FAILED, $result->status);
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->sent_at);
        Mail::assertNothingSent();
    }

    public function test_disabled_notifications_send_no_email(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);

        $result = $this->sender->send($this->makeReminder(7));

        $this->assertSame(SubscriptionReminderSendResult::STATUS_DISABLED, $result->status);
        Mail::assertNothingSent();
    }

    public function test_channel_override_email_without_changing_config(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');

        $reminder = $this->makeReminder(1);
        $result = $this->sender->send($reminder, EmailSubscriptionReminderChannel::CHANNEL_NAME);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_SENT, $result->status);
        Mail::assertSent(SubscriptionReminderMail::class, 1);
    }

    private function makeReminder(int $threshold): SubscriptionReminder
    {
        $client = Client::query()->create([
            'company_name' => 'Client Email Delivery',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Boutique Delivery',
            'subdomain' => 'deliver286-'.uniqid(),
            'status' => 'active',
        ]);

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $service = app(SubscriptionReminderService::class);

        return SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => $threshold,
            'scheduled_for' => $service->resolveScheduledFor($subscription, $threshold),
            'detected_at' => '2026-10-24 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
    }
}
