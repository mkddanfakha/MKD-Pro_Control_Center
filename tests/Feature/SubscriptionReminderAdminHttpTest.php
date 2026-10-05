<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionReminderAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_cannot_access_subscription_reminders_index(): void
    {
        $this->get(route('subscription-reminders.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_control_center_user_can_view_subscription_reminders_index(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->createReminder(status: SubscriptionReminder::STATUS_DETECTED);

        $this->actingAs($user)
            ->get(route('subscription-reminders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SubscriptionReminders/Index')
                ->has('reminders.data', 1)
                ->where('reminders.data.0.id', $reminder->id)
                ->where('stats.total', 1)
                ->where('stats.detected', 1));
    }

    public function test_authenticated_user_without_control_center_access_is_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('subscription-reminders.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_bypass_authorization_with_query_parameters(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $this->createReminder();

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', [
                'status' => 'detected',
                'search' => 'central',
                'installation_id' => 1,
            ]))
            ->assertForbidden();
    }

    public function test_authorized_user_can_apply_existing_filters(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->createReminder(status: SubscriptionReminder::STATUS_FAILED);

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', ['status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'failed')
                ->has('reminders.data', 1));
    }

    public function test_index_lists_reminders_with_installation_context(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->createReminder(
            installationName: 'Boutique Rappels',
            status: SubscriptionReminder::STATUS_SENT,
            sentAt: '2026-10-24 14:00:00',
        );

        $this->actingAs($user)
            ->get(route('subscription-reminders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reminders.data.0.installation_name', 'Boutique Rappels')
                ->where('reminders.data.0.status', SubscriptionReminder::STATUS_SENT)
                ->where('reminders.data.0.subscription_id', $reminder->subscription_id));
    }

    public function test_pagination_preserves_filters(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($i = 0; $i < 26; $i++) {
            $this->createReminder(
                installationName: 'Paginate '.$i,
                threshold: 7,
                scheduledFor: sprintf('2026-10-%02d 00:00:00', 1 + ($i % 28)),
            );
        }

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', ['status' => 'detected']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 25)
                ->where('filters.status', 'detected'));

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', ['status' => 'detected', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('filters.status', 'detected'));
    }

    public function test_filter_by_status(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->createReminder(status: SubscriptionReminder::STATUS_DETECTED);
        $this->createReminder(status: SubscriptionReminder::STATUS_SENT, sentAt: '2026-10-24 10:00:00');
        $this->createReminder(status: SubscriptionReminder::STATUS_FAILED);

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', ['status' => 'failed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('reminders.data.0.status', SubscriptionReminder::STATUS_FAILED)
                ->where('stats.failed', 1));
    }

    public function test_filter_by_installation(): void
    {
        $user = $this->controlCenterAdminUser();
        $first = $this->createReminder(installationName: 'Alpha');
        $this->createReminder(installationName: 'Beta');

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', [
                'installation_id' => $first->subscription->installation_id,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('reminders.data.0.installation_name', 'Alpha'));
    }

    public function test_filter_by_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $target = $this->createReminder();
        $this->createReminder();

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', [
                'subscription_id' => $target->subscription_id,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('reminders.data.0.subscription_id', $target->subscription_id));
    }

    public function test_filter_by_threshold_days(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->createReminder(threshold: 7);
        $this->createReminder(threshold: 3, scheduledFor: '2026-10-28 00:00:00');

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', ['threshold_days' => 3]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('reminders.data.0.threshold_days', 3));
    }

    public function test_search_by_installation_name_or_subdomain(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->createReminder(installationName: 'Magasin Central', subdomain: 'central-291');
        $this->createReminder(installationName: 'Autre', subdomain: 'autre-291');

        $this->actingAs($user)
            ->get(route('subscription-reminders.index', ['search' => 'central-291']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('reminders.data.0.installation_subdomain', 'central-291'));
    }

    public function test_default_order_is_created_at_desc(): void
    {
        $user = $this->controlCenterAdminUser();
        $older = $this->createReminder(installationName: 'Older');
        $newer = $this->createReminder(installationName: 'Newer');

        SubscriptionReminder::query()->whereKey($older->id)->update([
            'created_at' => '2026-10-20 08:00:00',
        ]);
        SubscriptionReminder::query()->whereKey($newer->id)->update([
            'created_at' => '2026-10-22 08:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscription-reminders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reminders.data.0.id', $newer->id)
                ->where('reminders.data.1.id', $older->id));
    }

    public function test_detected_sent_and_failed_statuses_are_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->createReminder(status: SubscriptionReminder::STATUS_DETECTED);
        $this->createReminder(status: SubscriptionReminder::STATUS_SENT, sentAt: '2026-10-24 09:00:00');
        $this->createReminder(status: SubscriptionReminder::STATUS_FAILED);

        $this->actingAs($user)
            ->get(route('subscription-reminders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 3)
                ->where('stats.detected', 1)
                ->where('stats.sent', 1)
                ->where('stats.failed', 1));
    }

    public function test_viewing_index_does_not_mutate_data_or_send_mail(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->createSubscription();
        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 1,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
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

        $subscriptionSnapshot = $subscription->fresh()->only(['status', 'current_period_end', 'amount']);
        $paymentCount = Payment::query()->count();
        $reminderSnapshot = $reminder->fresh()->only(['status', 'sent_at']);

        $this->actingAs($user)->get(route('subscription-reminders.index'))->assertOk();
        $this->actingAs($user)->get(route('subscription-reminders.index', ['search' => 'test']))->assertOk();

        $this->assertSame($subscriptionSnapshot['status'], $subscription->fresh()->status);
        $this->assertSame(
            $subscriptionSnapshot['current_period_end']?->format('Y-m-d H:i:s'),
            $subscription->fresh()->current_period_end?->format('Y-m-d H:i:s'),
        );
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($reminderSnapshot['status'], $reminder->fresh()->status);
        $this->assertSame(
            $reminderSnapshot['sent_at']?->format('Y-m-d H:i:s'),
            $reminder->fresh()->sent_at?->format('Y-m-d H:i:s'),
        );

        Mail::assertNothingSent();
    }

    private function createReminder(
        string $installationName = 'Installation Test',
        string $subdomain = '',
        int $threshold = 7,
        string $scheduledFor = '2026-10-24 00:00:00',
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        $subscription = $this->createSubscription($installationName, $subdomain);

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

    private function createSubscription(
        string $installationName = 'Installation Test',
        string $subdomain = '',
    ): Subscription {
        $client = Client::query()->create([
            'company_name' => 'Client Admin Reminder',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => $installationName,
            'subdomain' => $subdomain !== '' ? $subdomain : 'rem291-'.uniqid(),
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
