<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('app.timezone', 'UTC');
        $this->service = app(SubscriptionReminderService::class);
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_creates_detected_reminder_with_expected_fields(): void
    {
        $subscription = $this->makeSubscription();

        $result = $this->service->recordDetectedReminder($subscription, 7);

        $this->assertTrue($result['created']);
        $this->assertSame('recorded', $result['outcome']);
        $this->assertSame(SubscriptionReminder::STATUS_DETECTED, $result['reminder']->status);
        $this->assertSame(SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY, $result['reminder']->reminder_type);
        $this->assertSame(7, $result['reminder']->threshold_days);
        $this->assertNotNull($result['reminder']->detected_at);
        $this->assertNull($result['reminder']->sent_at);
    }

    public function test_subscription_has_many_reminders_relation(): void
    {
        $subscription = $this->makeSubscription();
        $this->service->recordDetectedReminder($subscription, 7);

        $this->assertCount(1, $subscription->fresh()->reminders);
    }

    public function test_reminder_belongs_to_subscription(): void
    {
        $subscription = $this->makeSubscription();
        $reminder = $this->service->recordDetectedReminder($subscription, 7)['reminder'];

        $this->assertTrue($reminder->subscription->is($subscription));
    }

    public function test_datetime_casts_are_applied(): void
    {
        $subscription = $this->makeSubscription();
        $reminder = $this->service->recordDetectedReminder($subscription, 7)['reminder']->fresh();

        $this->assertInstanceOf(Carbon::class, $reminder->scheduled_for);
        $this->assertInstanceOf(Carbon::class, $reminder->detected_at);
    }

    public function test_fillable_allows_mass_assignment(): void
    {
        $subscription = $this->makeSubscription();

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 3,
            'scheduled_for' => '2026-10-24 00:00:00',
            'detected_at' => '2026-10-24 12:00:00',
            'sent_at' => null,
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->assertSame(3, $reminder->threshold_days);
    }

    public function test_database_unique_constraint_prevents_duplicate_logical_key(): void
    {
        $subscription = $this->makeSubscription();
        $scheduledFor = $this->service->resolveScheduledFor($subscription, 7);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => now(),
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => now(),
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
    }

    public function test_multiple_thresholds_for_same_subscription(): void
    {
        $subscription = $this->makeSubscription();

        $this->service->recordDetectedReminder($subscription, 7);
        $this->service->recordDetectedReminder($subscription, 3);

        $this->assertSame(2, SubscriptionReminder::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_record_is_idempotent_via_service(): void
    {
        $subscription = $this->makeSubscription();

        $first = $this->service->recordDetectedReminder($subscription, 7);
        $second = $this->service->recordDetectedReminder($subscription, 7);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['reminder']->id, $second['reminder']->id);
        $this->assertSame(1, SubscriptionReminder::query()->count());
    }

    public function test_existing_sent_reminder_is_not_modified(): void
    {
        $subscription = $this->makeSubscription();
        $scheduledFor = $this->service->resolveScheduledFor($subscription, 7);

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 10:00:00',
            'sent_at' => '2026-10-24 11:00:00',
            'status' => SubscriptionReminder::STATUS_SENT,
        ]);

        Carbon::setTestNow('2026-10-24 15:00:00');

        $result = $this->service->recordDetectedReminder($subscription, 7);
        $fresh = $result['reminder']->fresh();

        $this->assertFalse($result['created']);
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-24 10:00:00', $fresh->detected_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24 11:00:00', $fresh->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_existing_failed_reminder_is_not_modified(): void
    {
        $subscription = $this->makeSubscription();
        $scheduledFor = $this->service->resolveScheduledFor($subscription, 7);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 10:00:00',
            'sent_at' => null,
            'status' => SubscriptionReminder::STATUS_FAILED,
        ]);

        $result = $this->service->recordDetectedReminder($subscription, 7);
        $fresh = $result['reminder']->fresh();

        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $fresh->status);
        $this->assertSame('2026-10-24 10:00:00', $fresh->detected_at->format('Y-m-d H:i:s'));
    }

    public function test_scheduled_for_aligns_with_period_end_and_threshold(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $scheduled = $this->service->resolveScheduledFor($subscription, 7);

        $this->assertSame('2026-10-24 00:00:00', $scheduled->format('Y-m-d H:i:s'));
    }

    public function test_service_does_not_mutate_subscription(): void
    {
        $subscription = $this->makeSubscription();
        $before = $subscription->fresh()->toArray();

        $this->service->recordDetectedReminder($subscription, 7);

        $this->assertSame($before, $subscription->fresh()->toArray());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Reminder Persist',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'rempersist-'.uniqid(),
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
