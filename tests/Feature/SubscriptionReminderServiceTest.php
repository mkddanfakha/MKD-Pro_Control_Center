<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.subscription_reminders.enabled', false);
        Config::set('app.timezone', 'UTC');
        $this->service = app(SubscriptionReminderService::class);
    }

    public function test_reminders_disabled_returns_reminders_disabled_reason(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 15:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_REMINDERS_DISABLED, $result['reason']);
    }

    public function test_seven_days_before_end_when_enabled(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertTrue($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_REMINDER_DUE, $result['reason']);
        $this->assertSame(7, $result['days_remaining']);
        $this->assertSame(7, $result['matched_threshold']);
        $this->assertSame(SubscriptionReminderService::TEMPORAL_DUE_SOON, $result['temporal_state']);
    }

    public function test_three_days_before_end_when_enabled(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-27 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 00:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertTrue($result['eligible']);
        $this->assertSame(3, $result['matched_threshold']);
    }

    public function test_one_day_before_end_when_enabled(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-25 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 00:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1, $result['matched_threshold']);
    }

    public function test_on_expiry_day_threshold_zero(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-31 18:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertTrue($result['eligible']);
        $this->assertSame(0, $result['matched_threshold']);
        $this->assertSame(0, $result['days_remaining']);
    }

    public function test_future_period_is_not_due_soon(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-12-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::TEMPORAL_FUTURE, $result['temporal_state']);
        $this->assertSame(SubscriptionReminderService::REASON_NO_MATCHING_THRESHOLD, $result['reason']);
    }

    public function test_current_period_without_matching_threshold(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-20 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::TEMPORAL_CURRENT, $result['temporal_state']);
        $this->assertSame(SubscriptionReminderService::REASON_NO_MATCHING_THRESHOLD, $result['reason']);
    }

    public function test_expired_period(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_PERIOD_EXPIRED, $result['reason']);
        $this->assertSame(SubscriptionReminderService::TEMPORAL_EXPIRED, $result['temporal_state']);
    }

    public function test_grace_period_is_not_remindable(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_GRACE_PERIOD, $result['reason']);
    }

    public function test_suspended_is_not_remindable(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_SUSPENDED, $result['reason']);
    }

    public function test_terminated_is_never_remindable(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_TERMINATED, $result['reason']);
    }

    public function test_missing_period_end(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => null,
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');

        $result = $this->service->assessReminder($subscription);

        $this->assertFalse($result['eligible']);
        $this->assertSame(SubscriptionReminderService::REASON_NO_PERIOD_END, $result['reason']);
    }

    public function test_multiple_subscriptions_assessment(): void
    {
        $this->enableReminders();
        Carbon::setTestNow('2026-10-24 12:00:00');

        $due = $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $notDue = $this->makeSubscription([
            'current_period_end' => '2026-11-30 23:59:59',
        ]);

        $this->assertTrue($this->service->assessReminder($due)['eligible']);
        $this->assertFalse($this->service->assessReminder($notDue)['eligible']);
    }

    public function test_query_subscription_filter_via_command_scope(): void
    {
        $this->enableReminders();
        $target = $this->makeSubscription();
        $this->makeSubscription();

        $ids = $this->service->querySubscriptionsForReminderAudit(false)
            ->whereKey($target->id)
            ->pluck('id')
            ->all();

        $this->assertSame([$target->id], $ids);
    }

    public function test_query_installation_filter(): void
    {
        $this->enableReminders();
        $installation = $this->makeInstallation();
        $other = $this->makeInstallation();

        $this->makeSubscription(['installation_id' => $installation->id]);
        $this->makeSubscription(['installation_id' => $other->id]);

        $count = $this->service->querySubscriptionsForReminderAudit(false)
            ->where('installation_id', $installation->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_days_remaining_uses_utc_calendar_days(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 23:59:59');

        $days = $this->service->daysRemainingUntilPeriodEnd($subscription);

        $this->assertSame(7, $days);
    }

    public function test_assessment_does_not_mutate_subscription(): void
    {
        $this->enableReminders();

        $subscription = $this->makeSubscription([
            'amount' => 15000,
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');

        $before = $subscription->fresh()->toArray();
        $this->service->assessReminder($subscription);
        $after = $subscription->fresh()->toArray();

        $this->assertSame($before, $after);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.subscription_reminders.enabled', false);
        parent::tearDown();
    }

    private function enableReminders(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $installationId = $attributes['installation_id'] ?? $this->makeInstallation()->id;
        unset($attributes['installation_id']);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installationId,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client Reminder 281',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation-'.uniqid(),
            'subdomain' => 'rem281-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
