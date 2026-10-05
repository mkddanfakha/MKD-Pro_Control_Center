<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionReminderShowAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $reminder = $this->makeReminder();

        $this->get(route('subscription-reminders.show', $reminder))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $reminder = $this->makeReminder();

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_show_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder();

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SubscriptionReminders/Show')
                ->where('reminder.id', $reminder->id)
                ->has('navigation'));
    }

    public function test_missing_reminder_returns_not_found(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get('/subscription-reminders/999999')
            ->assertNotFound();
    }

    public function test_reminder_general_fields_and_status(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder([
            'threshold_days' => 3,
            'status' => SubscriptionReminder::STATUS_SENT,
            'sent_at' => '2026-10-28 08:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reminder.reminder_type', SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY)
                ->where('reminder.threshold_days', 3)
                ->where('reminder.status', SubscriptionReminder::STATUS_SENT)
                ->where('reminder.sent_at', '2026-10-28 08:00:00')
                ->has('reminder.scheduled_for')
                ->has('reminder.detected_at')
                ->has('reminder.created_at')
                ->has('reminder.updated_at'));
    }

    public function test_notification_preview_is_composed_read_only(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder();

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notification')
                ->where('notification.channel', 'email')
                ->where('notification.recipient_label', 'Administrateur central MKD-Pro')
                ->has('notification.title')
                ->has('notification.body')
                ->has('notification.amount'));

        Mail::assertNothingSent();
    }

    public function test_subscription_installation_and_client_context(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder();
        $subscription = Subscription::query()->findOrFail($reminder->subscription_id);
        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $client = Client::query()->findOrFail($installation->client_id);

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.id', $subscription->id)
                ->where('installation.id', $installation->id)
                ->where('client.id', $client->id)
                ->where('navigation.subscription_show', route('subscriptions.show', $subscription))
                ->where('navigation.installation_show', route('installations.show', $installation))
                ->where('navigation.client_show', route('clients.show', $client))
                ->where('navigation.reminders_index', route('subscription-reminders.index')));
    }

    public function test_subscription_payments_are_listed_with_show_links(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder();
        $payment = Payment::query()->create([
            'subscription_id' => $reminder->subscription_id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-10 10:00:00',
            'credit_months_purchased' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.show_url', route('payments.show', $payment)));
    }

    public function test_payments_are_paginated_ten_per_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder();

        for ($i = 0; $i < 11; $i++) {
            Payment::query()->create([
                'subscription_id' => $reminder->subscription_id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Payment::STATUS_PAID,
                'paid_at' => now()->subDays($i),
            ]);
        }

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.per_page', 10)
                ->has('payments.data', 10));

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', [$reminder, 'payments_page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1));
    }

    public function test_reminder_audit_history_and_audit_navigation(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder();

        AuditLog::query()->create([
            'action' => 'reminder.test_event',
            'auditable_type' => $reminder->getMorphClass(),
            'auditable_id' => $reminder->id,
            'result' => 'success',
        ]);

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit_history', 1)
                ->where('audit_history.0.action', 'reminder.test_event')
                ->where(
                    'navigation.audit_logs_index',
                    route('audit-logs.index', [
                        'auditable_type' => $reminder->getMorphClass(),
                        'auditable_id' => $reminder->id,
                    ]),
                ));
    }

    public function test_installation_payload_excludes_database_secrets(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Reminder Client',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Reminder Site',
            'subdomain' => 'rem-'.uniqid(),
            'status' => 'active',
            'database_name' => 'secret_db',
            'database_host' => '10.0.0.5',
        ]);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-24 00:00:00',
            'detected_at' => '2026-10-24 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('installation.database_name')
                ->missing('installation.database_host'));
    }

    public function test_show_is_read_only_and_does_not_send_mail(): void
    {
        $user = $this->controlCenterAdminUser();
        $reminder = $this->makeReminder(['status' => SubscriptionReminder::STATUS_DETECTED]);
        $statusBefore = $reminder->fresh()->status;

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk();

        $this->assertSame($statusBefore, $reminder->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_show_route_is_get_only(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => $route->getName() === 'subscription-reminders.show',
        );

        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_show_vue_has_no_business_actions(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/SubscriptionReminders/Show.vue'));

        $this->assertStringNotContainsString('router.post', $contents);
        $this->assertStringNotContainsString('renew-subscription', $contents);
        $this->assertStringNotContainsString('consume-credit', $contents);
    }

    /**
     * @param  array<string, mixed>  $reminderAttributes
     */
    private function makeReminder(array $reminderAttributes = []): SubscriptionReminder
    {
        $client = Client::query()->create([
            'company_name' => 'Show Reminder Client',
            'contact_name' => 'Contact',
            'email' => 'reminder@example.com',
            'phone' => '+221111111',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Rappel Show',
            'subdomain' => 'rem-show-'.uniqid(),
            'domain' => 'rem.example.com',
            'status' => 'active',
            'version' => '2.0',
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

        return SubscriptionReminder::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-24 00:00:00',
            'detected_at' => '2026-10-24 12:00:00',
            'sent_at' => null,
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ], $reminderAttributes));
    }
}
