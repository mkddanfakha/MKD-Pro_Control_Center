<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use App\Services\SubscriptionReminderNotificationSender;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardOperationalStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-24 15:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_with_control_center_access_sees_dashboard(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_authenticated_user_denied_control_center_access_receives_forbidden(): void
    {
        $user = User::factory()->create();

        Gate::before(fn ($authUser, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_dashboard_exposes_installation_status_breakdown_including_inactive(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeInstallation(['status' => 'active']);
        $this->makeInstallation(['status' => 'inactive']);
        $this->makeInstallation(['status' => 'suspended']);
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalInstallations', 4)
                ->where('activeInstallations', 1)
                ->where('inactiveInstallations', 1)
                ->where('suspendedInstallations', 1)
                ->where('terminatedInstallations', 1));
    }

    public function test_dashboard_exposes_subscription_status_breakdown(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeSubscription(['status' => Subscription::STATUS_ACTIVE]);
        $this->makeSubscription(['status' => Subscription::STATUS_GRACE_PERIOD]);
        $this->makeSubscription(['status' => Subscription::STATUS_SUSPENDED]);
        $this->makeSubscription(['status' => Subscription::STATUS_TERMINATED]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalSubscriptions', 4)
                ->where('activeSubscriptions', 1)
                ->where('gracePeriodSubscriptions', 1)
                ->where('suspendedSubscriptions', 1)
                ->where('terminatedSubscriptions', 1));
    }

    public function test_dashboard_subscription_due_stats_align_with_reminder_service_thresholds(): void
    {
        $user = $this->controlCenterAdminUser();
        $service = app(SubscriptionReminderService::class);

        $periodEnd = '2026-10-31 23:59:59';
        $atSeven = $this->makeSubscription([
            'current_period_end' => $periodEnd,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $atThree = $this->makeSubscription([
            'current_period_end' => $periodEnd,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->makeSubscription([
            'current_period_end' => '2026-11-15 00:00:00',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->makeSubscription([
            'current_period_end' => '2026-10-20 00:00:00',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertSame(7, $service->daysRemainingUntilPeriodEnd($atSeven->fresh()));
        $this->assertSame(7, $service->daysRemainingUntilPeriodEnd($atThree->fresh()));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscriptionDueStats.reference_date_utc', '2026-10-24')
                ->where('subscriptionDueStats.due_in_seven_days', 2)
                ->where('subscriptionDueStats.future', 1)
                ->where('subscriptionDueStats.period_expired', 1));
    }

    public function test_dashboard_subscription_due_stats_cover_j3_j1_and_j0(): void
    {
        $user = $this->controlCenterAdminUser();

        Carbon::setTestNow('2026-10-28 10:00:00');

        $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->makeSubscription([
            'current_period_end' => '2026-10-29 12:00:00',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->makeSubscription([
            'current_period_end' => '2026-10-28 18:00:00',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscriptionDueStats.due_in_three_days', 1)
                ->where('subscriptionDueStats.due_tomorrow', 1)
                ->where('subscriptionDueStats.due_today', 1));
    }

    public function test_dashboard_subscription_reminder_statistics(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-17 00:00:00',
            'detected_at' => '2026-10-17 08:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 3,
            'scheduled_for' => '2026-10-21 00:00:00',
            'detected_at' => '2026-10-21 08:00:00',
            'status' => SubscriptionReminder::STATUS_SENT,
            'sent_at' => '2026-10-21 08:00:00',
        ]);
        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 1,
            'scheduled_for' => '2026-10-23 00:00:00',
            'detected_at' => '2026-10-23 08:00:00',
            'status' => SubscriptionReminder::STATUS_FAILED,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscriptionReminderStats.detected', 1)
                ->where('subscriptionReminderStats.sent', 1)
                ->where('subscriptionReminderStats.failed', 1)
                ->where('subscriptionReminderStats.total', 3));
    }

    public function test_dashboard_does_not_mutate_subscriptions_or_reminders(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-17 00:00:00',
            'detected_at' => '2026-10-17 08:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $subscriptionSnapshot = [
            'status' => $subscription->fresh()->status,
            'current_period_end' => $subscription->fresh()->current_period_end?->format('Y-m-d H:i:s'),
        ];
        $reminderSnapshot = [
            'status' => $reminder->fresh()->status,
            'sent_at' => $reminder->fresh()->sent_at?->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $freshSubscription = $subscription->fresh();
        $this->assertSame($subscriptionSnapshot['status'], $freshSubscription->status);
        $this->assertSame(
            $subscriptionSnapshot['current_period_end'],
            $freshSubscription->current_period_end?->format('Y-m-d H:i:s'),
        );

        $freshReminder = $reminder->fresh();
        $this->assertSame($reminderSnapshot['status'], $freshReminder->status);
        $this->assertSame(
            $reminderSnapshot['sent_at'],
            $freshReminder->sent_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_dashboard_does_not_send_email_or_invoke_reminder_sender(): void
    {
        Mail::fake();

        $sender = $this->createMock(SubscriptionReminderNotificationSender::class);
        $sender->expects($this->never())->method('send');
        $this->app->instance(SubscriptionReminderNotificationSender::class, $sender);

        $user = $this->controlCenterAdminUser();
        $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        Mail::assertNothingSent();
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
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInstallation(array $overrides = []): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Installation '.uniqid(),
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ], $overrides));
    }
}
