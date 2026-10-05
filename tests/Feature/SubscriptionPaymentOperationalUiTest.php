<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionPaymentOperationalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_shows_consume_action_when_credit_available(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Edit')
                ->where('operational_actions.can_consume_credit', true)
                ->where('credit.available_months', 1)
                ->has('operational_actions.consume_credit_url'));
    }

    public function test_edit_hides_consume_action_when_no_credit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $this->actingAs($user)
            ->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('operational_actions.can_consume_credit', false)
                ->where('credit.available_months', 0));
    }

    public function test_edit_hides_consume_action_when_subscription_terminated(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-30 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('operational_actions.can_consume_credit', false));

        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Edit.vue'));
        $this->assertStringContainsString('operational_actions.can_consume_credit', $contents);
    }

    public function test_consume_credit_from_ui_route_extends_period(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->post(route('subscriptions.consume-credit', $subscription))
            ->assertRedirect(route('subscriptions.show', $subscription))
            ->assertSessionHas('success');

        $subscription->refresh();
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_consume_credit_during_grace_period_reactivates_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('subscriptions.edit', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('operational_actions.can_consume_credit', true));

        $this->actingAs($user)
            ->post(route('subscriptions.consume-credit', $subscription))
            ->assertSessionHas('success');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_consume_credit_during_suspended_allows_action_and_reactivates(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 08:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('subscriptions.edit', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('operational_actions.can_consume_credit', true));

        $this->actingAs($user)
            ->post(route('subscriptions.consume-credit', $subscription))
            ->assertSessionHas('success');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    public function test_subscription_edit_vue_does_not_offer_manual_payment_selection_for_fifo(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Edit.vue'));

        $this->assertStringContainsString('Consommer 1 mois de crédit', $contents);
        $this->assertStringContainsString('operational_actions.consume_credit_url', $contents);
        $this->assertStringNotContainsString('renew-subscription', $contents);
        $this->assertDoesNotMatchRegularExpression('/select[^>]+payment/i', $contents);
    }

    public function test_consume_credit_creates_subscription_credit_consumed_audit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
    }

    public function test_payment_edit_shows_renew_action_when_eligible(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('payments.edit', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Payments/Edit')
                ->where('operational_actions.can_renew_from_payment', true)
                ->has('operational_actions.renew_subscription_url'));
    }

    public function test_payment_edit_hides_renew_when_no_credit_remaining(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('payments.edit', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('operational_actions.can_renew_from_payment', false));
    }

    public function test_renew_subscription_refused_when_subscription_terminated(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-30 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->get(route('payments.edit', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('operational_actions.can_renew_from_payment', false));

        $periodEnd = $subscription->current_period_end->format('Y-m-d H:i:s');

        $this->actingAs($user)
            ->post(route('payments.renew-subscription', $payment))
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHas('error');

        $this->assertSame($periodEnd, $subscription->fresh()->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_renew_subscription_updates_period_and_audits_success(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->post(route('payments.renew-subscription', $payment))
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHas('success');

        $this->assertSame('2026-11-01 00:00:00', $subscription->fresh()->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
    }

    public function test_double_renewal_on_exhausted_payment_is_rejected_without_duplicate_audit_success(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)->post(route('payments.renew-subscription', $payment))->assertSessionHas('success');

        $this->actingAs($user)
            ->post(route('payments.renew-subscription', $payment))
            ->assertSessionHas('error');

        $this->assertSame(1, AuditLog::query()->where('action', 'payment.renewal_applied')->count());
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('payment_id', $payment->id)->count());
    }

    public function test_subscription_edit_reflects_automatic_renewal_disabled(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $this->assertFalse(config('subscriptions.automatic_credit_renewal.enabled'));

        $this->actingAs($user)
            ->get(route('subscriptions.edit', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('automatic_credit_renewal.enabled', false));

        $editVue = file_get_contents(base_path('resources/js/Pages/Subscriptions/Edit.vue'));
        $this->assertStringContainsString('renouvellement automatique', strtolower($editVue));
        $this->assertStringNotContainsString('subscriptions:renew-with-credit', $editVue);
    }

    public function test_no_ui_action_triggers_automatic_renewal_scheduler_command(): void
    {
        $vueFiles = [
            'resources/js/Pages/Subscriptions/Edit.vue',
            'resources/js/Pages/Subscriptions/Payments/Edit.vue',
        ];

        foreach ($vueFiles as $path) {
            $contents = file_get_contents(base_path($path));
            $this->assertStringNotContainsString('subscriptions:renew-with-credit', $contents);
            $this->assertStringNotContainsString('RenewSubscriptionsWithCredit', $contents);
        }
    }

    public function test_operational_post_routes_require_control_center_access(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->actingAs($user)
            ->post(route('subscriptions.consume-credit', $subscription))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('payments.renew-subscription', $payment))
            ->assertForbidden();
    }

    public function test_payment_edit_vue_distinguishes_renew_label_from_subscription_consume(): void
    {
        $subscriptionEdit = file_get_contents(base_path('resources/js/Pages/Subscriptions/Edit.vue'));
        $paymentEdit = file_get_contents(base_path('resources/js/Pages/Subscriptions/Payments/Edit.vue'));

        $this->assertStringContainsString('Consommer 1 mois de crédit', $subscriptionEdit);
        $this->assertStringContainsString('Renouveler avec ce paiement', $paymentEdit);
        $this->assertStringNotContainsString('Renouveler avec ce paiement', $subscriptionEdit);
    }

    public function test_consume_credit_fifo_uses_oldest_paid_payment(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $paymentA = $this->makePaidPayment($subscription, 90000, 6, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $this->makePaidPayment($subscription, 45000, 3, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $consumption = SubscriptionPaymentConsumption::query()->sole();
        $this->assertSame($paymentA->id, $consumption->payment_id);
    }

    public function test_failed_consume_does_not_emit_success_audit(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $this->actingAs($user)->post(route('subscriptions.consume-credit', $subscription));

        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.credit_consumption_failed')->count());
    }

public function test_reminders_index_exposes_thresholds_and_french_status_labels_in_ui(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        foreach ([7, 3, 1, 0] as $threshold) {
            SubscriptionReminder::query()->create([
                'subscription_id' => $subscription->id,
                'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
                'threshold_days' => $threshold,
                'scheduled_for' => '2026-10-24 00:00:00',
                'detected_at' => '2026-10-24 12:00:00',
                'status' => SubscriptionReminder::STATUS_DETECTED,
            ]);
        }

        $this->actingAs($user)
            ->get(route('subscription-reminders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SubscriptionReminders/Index')
                ->has('reminders.data', 4));

        $indexVue = file_get_contents(base_path('resources/js/Pages/SubscriptionReminders/Index.vue'));
        $this->assertStringContainsString('timeZone: \'UTC\'', $indexVue);
        $this->assertStringContainsString('reminderStatusLabel', $indexVue);

        $presentation = file_get_contents(base_path('resources/js/lib/adminPresentation.js'));
        $this->assertStringContainsString('Détecté', $presentation);
        $this->assertStringContainsString('Envoyé', $presentation);
        $this->assertStringContainsString('Échec d’envoi', $presentation);
    }

    public function test_reminder_show_serializes_utc_dates(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 3,
            'scheduled_for' => '2026-10-28 00:00:00',
            'detected_at' => '2026-10-27 08:00:00',
            'status' => SubscriptionReminder::STATUS_SENT,
            'sent_at' => '2026-10-27 09:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscription-reminders.show', $reminder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reminder.threshold_days', 3)
                ->where('reminder.status', SubscriptionReminder::STATUS_SENT)
                ->where('reminder.detected_at', '2026-10-27 08:00:00'));

        $showVue = file_get_contents(base_path('resources/js/Pages/SubscriptionReminders/Show.vue'));
        $this->assertStringContainsString('timeZone: \'UTC\'', $showVue);
    }

    public function test_scheduler_lists_subscription_operational_commands(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('subscriptions:renew-with-credit', $output);
        $this->assertStringContainsString('subscriptions:process-reminders', $output);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société opérationnelle UI',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation op UI',
            'subdomain' => 'op-ui-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePaidPayment(Subscription $subscription, int $amount, int $months, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ], $attributes));
    }
}
