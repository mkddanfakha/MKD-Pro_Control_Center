<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.subscription_reminders.enabled', false);
        Config::set('app.timezone', 'UTC');
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_command_shows_disabled_and_is_read_only(): void
    {
        $subscription = $this->makeSubscription();
        $before = $this->captureState($subscription);

        Artisan::call('subscriptions:reminder-audit');
        $output = Artisan::output();

        $this->assertStringContainsString('DISABLED', $output);
        $this->assertSame($before, $this->captureState($subscription->fresh()));
        $this->assertSame(0, SubscriptionReminder::query()->count());
    }

    public function test_command_when_enabled_lists_due_soon(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('ENABLED', $output);
        $this->assertStringContainsString('#'.$subscription->id, $output);
        $this->assertStringContainsString('reminder=7', $output);
        $this->assertStringContainsString('Due soon            : 1', $output);
    }

    public function test_json_output_structure(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Artisan::call('subscriptions:reminder-audit', [
            '--subscription' => $subscription->id,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['reminders_enabled']);
        $this->assertSame('2026-10-24', $decoded['today']);
        $this->assertSame(1, $decoded['due_soon']);
        $this->assertSame($subscription->id, $decoded['subscriptions'][0]['subscription_id']);
        $this->assertSame(7, $decoded['subscriptions'][0]['matched_threshold']);
        $this->assertSame(1, $decoded['recorded']);
        $this->assertSame(0, $decoded['already_recorded']);
        $this->assertSame(0, $decoded['errors']);
    }

    public function test_subscription_filter(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $target = $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);
        $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $target->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Evaluated           : 1', $output);
        $this->assertStringContainsString('#'.$target->id, $output);
    }

    public function test_installation_filter(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $installation = $this->makeInstallation();
        $sub = $this->makeSubscription([
            'installation_id' => $installation->id,
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);

        Artisan::call('subscriptions:reminder-audit', ['--installation' => $installation->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Evaluated           : 1', $output);
        $this->assertStringContainsString('#'.$sub->id, $output);
    }

    public function test_all_option_shows_ineligible_with_reason(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        Artisan::call('subscriptions:reminder-audit', [
            '--subscription' => $subscription->id,
            '--all' => true,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('eligible=false reason=grace_period', $output);
    }

    public function test_enabled_persists_reminder_and_second_run_is_idempotent(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $firstOutput = Artisan::output();
        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertStringContainsString('Detected            : 1', $firstOutput);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $output = Artisan::output();

        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertStringContainsString('Already recorded    : 1', $output);
    }

    public function test_three_runs_keep_single_reminder_row(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);

        for ($i = 0; $i < 3; $i++) {
            Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        }

        $this->assertSame(1, SubscriptionReminder::query()->count());
    }

    public function test_thresholds_seven_three_one_and_zero_create_distinct_rows(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription([
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        Carbon::setTestNow('2026-10-24 12:00:00');
        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $this->assertSame(1, SubscriptionReminder::query()->where('threshold_days', 7)->count());

        Carbon::setTestNow('2026-10-28 12:00:00');
        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $this->assertSame(1, SubscriptionReminder::query()->where('threshold_days', 3)->count());

        Carbon::setTestNow('2026-10-30 12:00:00');
        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $this->assertSame(1, SubscriptionReminder::query()->where('threshold_days', 1)->count());

        Carbon::setTestNow('2026-10-31 12:00:00');
        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);
        $this->assertSame(1, SubscriptionReminder::query()->where('threshold_days', 0)->count());
        $this->assertSame(4, SubscriptionReminder::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_dry_run_does_not_persist_even_when_enabled(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);

        Artisan::call('subscriptions:reminder-audit', [
            '--subscription' => $subscription->id,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, SubscriptionReminder::query()->count());
        $this->assertStringContainsString('Would record        : 1', $output);
    }

    public function test_existing_sent_reminder_unchanged_after_audit(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);
        $service = app(\App\Services\SubscriptionReminderService::class);
        $scheduledFor = $service->resolveScheduledFor($subscription, 7);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 08:00:00',
            'sent_at' => '2026-10-24 09:00:00',
            'status' => SubscriptionReminder::STATUS_SENT,
        ]);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);

        $reminder = SubscriptionReminder::query()->firstOrFail();
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->status);
        $this->assertSame('2026-10-24 09:00:00', $reminder->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_existing_failed_reminder_unchanged_after_audit(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $subscription = $this->makeSubscription(['current_period_end' => '2026-10-31 23:59:59']);
        $service = app(\App\Services\SubscriptionReminderService::class);
        $scheduledFor = $service->resolveScheduledFor($subscription, 7);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 08:00:00',
            'sent_at' => null,
            'status' => SubscriptionReminder::STATUS_FAILED,
        ]);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);

        $this->assertSame(SubscriptionReminder::STATUS_FAILED, SubscriptionReminder::query()->firstOrFail()->status);
    }

    public function test_no_subscription_payment_or_audit_side_effects_when_enabled(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);

        $offerVersion = $this->makeOfferVersion();
        $subscription = $this->makeSubscription([
            'offer_version_id' => $offerVersion->id,
            'amount' => 15000,
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $before = $this->captureState($subscription);

        Artisan::call('subscriptions:reminder-audit', ['--subscription' => $subscription->id]);

        $this->assertSame($before, $this->captureState($subscription->fresh()));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(1, SubscriptionReminder::query()->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.subscription_reminders.enabled', false);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function captureState(Subscription $subscription): array
    {
        return [
            'consumptions' => SubscriptionPaymentConsumption::query()->count(),
            'payments' => Payment::query()->count(),
            'audits' => AuditLog::query()->count(),
            'status' => $subscription->status,
            'period_end' => $subscription->current_period_end?->format('Y-m-d H:i:s'),
            'amount' => (int) $subscription->amount,
            'offer_version_id' => $subscription->offer_version_id,
        ];
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
            'company_name' => 'Client Reminder Audit',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation-'.uniqid(),
            'subdomain' => 'remaud-'.uniqid(),
            'status' => 'active',
        ]);
    }

    private function makePaidPayment(Subscription $subscription, int $amount, int $months): Payment
    {
        return Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => $months,
        ]);
    }

    private function makeOfferVersion(): OfferVersion
    {
        $product = Product::query()->create([
            'code' => 'P-REM-'.uniqid(),
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'O-REM-'.uniqid(),
            'name' => 'Offer',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        return OfferVersion::query()->create([
            'offer_id' => $offer->id,
            'version' => 'v-rem-'.uniqid(),
            'code' => 'V-REM-'.uniqid(),
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'status' => OfferVersion::STATUS_ACTIVE,
            'description' => 'Reminder',
            'inclusions' => [],
            'limitations' => [],
            'exclusions' => [],
        ]);
    }
}
