<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\PreparedSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderRecipient;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderNotificationSender;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderNotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionReminderNotificationSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');
        Config::set('app.timezone', 'UTC');
        $this->sender = app(SubscriptionReminderNotificationSender::class);
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_prepared_channel_marks_reminder_sent(): void
    {
        $reminder = $this->makeReminder(7);

        $result = $this->sender->send($reminder);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_SENT, $result->status);
        $this->assertSame(PreparedSubscriptionReminderChannel::CHANNEL_NAME, $result->channel);
        $this->assertSame(SubscriptionReminderRecipient::TYPE_CENTRAL_ADMIN, $result->recipientType);
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->fresh()->status);
    }

    public function test_disabled_notifications_return_disabled_without_channel_execution(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);

        $result = $this->sender->send($this->makeReminder(7));

        $this->assertSame(SubscriptionReminderSendResult::STATUS_DISABLED, $result->status);
    }

    public function test_unknown_channel_is_rejected(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'smtp');

        $this->expectException(\App\Exceptions\Subscription\SubscriptionReminderNotificationException::class);

        $this->sender->send($this->makeReminder(7));
    }

    public function test_thresholds_seven_three_one_zero(): void
    {
        foreach ([7, 3, 1, 0] as $threshold) {
            $reminder = $this->makeReminder($threshold);
            $result = $this->sender->send($reminder);
            $this->assertSame(SubscriptionReminderSendResult::STATUS_SENT, $result->status);
        }
    }

    public function test_already_sent_and_failed_are_terminal(): void
    {
        $sent = $this->makeReminder(7, SubscriptionReminder::STATUS_SENT, '2026-10-24 09:00:00');
        $this->assertSame(
            SubscriptionReminderSendResult::STATUS_ALREADY_SENT,
            $this->sender->send($sent)->status,
        );

        $failed = $this->makeReminder(7, SubscriptionReminder::STATUS_FAILED);
        $this->assertSame(
            SubscriptionReminderSendResult::STATUS_ALREADY_FAILED,
            $this->sender->send($failed)->status,
        );
    }

    public function test_no_subscription_or_payment_mutation(): void
    {
        $subscription = $this->makeSubscription(['amount' => 15000]);
        $this->makeReminderForSubscription($subscription, 7);

        $before = $subscription->fresh()->toArray();

        $this->sender->send($subscription->reminders()->first());

        $this->assertSame($before, $subscription->fresh()->toArray());
        $this->assertSame(
            0,
            SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count(),
        );
    }

    public function test_composition_error_is_propagated(): void
    {
        $subscription = $this->makeSubscription(['current_period_end' => null]);

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-24 00:00:00',
            'detected_at' => '2026-10-24 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->expectException(\App\Exceptions\Subscription\SubscriptionReminderNotificationException::class);

        $this->sender->send($reminder);
    }

    private function makeReminder(
        int $threshold,
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        return $this->makeReminderForSubscription($this->makeSubscription(), $threshold, $status, $sentAt);
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Sender 284',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Sender',
            'subdomain' => 'send284-'.uniqid(),
            'status' => 'active',
        ]);

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
}
