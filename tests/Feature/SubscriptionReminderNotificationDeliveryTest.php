<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\PreparedSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderNotificationSender;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class SubscriptionReminderNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionReminderService $reminderService;

    private SubscriptionReminderNotificationSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');
        Config::set('app.timezone', 'UTC');
        $this->reminderService = app(SubscriptionReminderService::class);
        $this->sender = app(SubscriptionReminderNotificationSender::class);
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_detected_becomes_sent_with_sent_at_utc(): void
    {
        $reminder = $this->makeReminder();

        $result = $this->sender->send($reminder);
        $fresh = $reminder->fresh();

        $this->assertSame(SubscriptionReminderSendResult::STATUS_SENT, $result->status);
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-24 12:00:00', $fresh->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_second_send_returns_already_sent_without_changing_sent_at(): void
    {
        $reminder = $this->makeReminder();

        $this->sender->send($reminder);
        $sentAt = $reminder->fresh()->sent_at;

        $mock = Mockery::mock(PreparedSubscriptionReminderChannel::class);
        $mock->shouldNotReceive('send');
        $this->app->instance(PreparedSubscriptionReminderChannel::class, $mock);
        $this->sender = app(SubscriptionReminderNotificationSender::class);

        $result = $this->sender->send($reminder->fresh());

        $this->assertSame(SubscriptionReminderSendResult::STATUS_ALREADY_SENT, $result->status);
        $this->assertSame($sentAt?->format('Y-m-d H:i:s'), $reminder->fresh()->sent_at?->format('Y-m-d H:i:s'));
    }

    public function test_mark_as_failed_from_detected(): void
    {
        $reminder = $this->makeReminder();

        $delivery = $this->reminderService->markAsFailed($reminder, 'Canal indisponible');
        $fresh = $delivery['reminder'];

        $this->assertSame(SubscriptionReminderService::DELIVERY_OUTCOME_FAILED, $delivery['outcome']);
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $fresh->status);
        $this->assertNull($fresh->sent_at);
    }

    public function test_failed_reminder_returns_already_failed_on_send(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_FAILED);

        $mock = Mockery::mock(PreparedSubscriptionReminderChannel::class);
        $mock->shouldNotReceive('send');
        $this->app->instance(PreparedSubscriptionReminderChannel::class, $mock);
        $this->sender = app(SubscriptionReminderNotificationSender::class);

        $result = $this->sender->send($reminder);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_ALREADY_FAILED, $result->status);
    }

    public function test_failed_never_becomes_sent_via_mark_as_sent(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_FAILED);

        $delivery = $this->reminderService->markAsSent($reminder);

        $this->assertSame(SubscriptionReminderService::DELIVERY_OUTCOME_ALREADY_FAILED, $delivery['outcome']);
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $reminder->fresh()->status);
    }

    public function test_sent_never_becomes_failed_via_mark_as_failed(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_SENT, '2026-10-24 09:00:00');

        $delivery = $this->reminderService->markAsFailed($reminder, 'test');

        $this->assertSame(SubscriptionReminderService::DELIVERY_OUTCOME_ALREADY_SENT, $delivery['outcome']);
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->fresh()->status);
    }

    public function test_channel_unavailable_marks_failed(): void
    {
        $reminder = $this->makeReminder();

        $mock = Mockery::mock(PreparedSubscriptionReminderChannel::class);
        $mock->shouldReceive('send')->once()->andReturn(new SubscriptionReminderSendResult(
            status: SubscriptionReminderSendResult::STATUS_UNAVAILABLE,
            channel: 'prepared',
            recipientType: 'central_admin',
            recipientId: null,
            message: 'Indisponible',
        ));
        $this->app->instance(PreparedSubscriptionReminderChannel::class, $mock);
        $this->sender = app(SubscriptionReminderNotificationSender::class);

        $result = $this->sender->send($reminder);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_FAILED, $result->status);
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $reminder->fresh()->status);
    }

    public function test_disabled_does_not_mutate_reminder(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);

        $reminder = $this->makeReminder();
        $before = $reminder->fresh()->toArray();

        $result = $this->sender->send($reminder);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_DISABLED, $result->status);
        $this->assertSame($before, $reminder->fresh()->toArray());
    }

    public function test_subscription_payment_and_installation_unchanged(): void
    {
        $subscription = $this->makeSubscription(['amount' => 19000]);
        $installation = Installation::query()->findOrFail($subscription->installation_id);
        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);

        $reminder = $this->makeReminderForSubscription($subscription, 7);

        $subBefore = $subscription->fresh()->toArray();
        $instBefore = $installation->fresh()->toArray();

        $this->sender->send($reminder);

        $this->assertSame($subBefore, $subscription->fresh()->toArray());
        $this->assertSame($instBefore, $installation->fresh()->toArray());
        $this->assertSame(
            0,
            SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count(),
        );
    }

    private function makeReminder(
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        return $this->makeReminderForSubscription($this->makeSubscription(), 7, $status, $sentAt);
    }

    private function makeReminderForSubscription(
        Subscription $subscription,
        int $threshold,
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        $scheduledFor = $this->reminderService->resolveScheduledFor($subscription, $threshold);

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
            'company_name' => 'Client Delivery 285',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Delivery',
            'subdomain' => 'del285-'.uniqid(),
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
