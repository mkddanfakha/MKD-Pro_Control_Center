<?php

namespace Tests\Feature;

use App\Mail\SubscriptionReminderMail;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Services\SubscriptionReminderNotificationComposer;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Validation contrôlée du pipeline rappels d’échéance — Task 288.
 *
 * Environnement : SQLite/MySQL de test (RefreshDatabase), données 100 % synthétiques.
 * Mail::fake() — aucun SMTP réel. Ne remplace pas une préproduction serveur dédiée.
 */
class SubscriptionReminderPreprodValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.timezone', 'UTC');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scenario_a_all_disabled_no_side_effects(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', false);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $subscription = $this->makeSubscriptionAtThreshold(7);
        $paymentCount = Payment::query()->count();
        $subscriptionSnapshot = $this->subscriptionBusinessSnapshot($subscription);

        Artisan::call('subscriptions:process-reminders', ['--dry-run' => true]);
        Artisan::call('subscriptions:process-reminders');

        $this->assertSame(0, SubscriptionReminder::query()->count());
        Mail::assertNothingSent();
        $this->assertSame($subscriptionSnapshot, $this->subscriptionBusinessSnapshot($subscription));
        $this->assertSame($paymentCount, Payment::query()->count());
    }

    public function test_scenario_b_detection_only_persists_without_notification(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');
        Carbon::setTestNow('2026-10-24 12:00:00');
        $subscription = $this->makeSubscriptionAtThreshold(7);
        $before = $this->subscriptionBusinessSnapshot($subscription);

        Artisan::call('subscriptions:process-reminders', ['--dry-run' => true]);
        $this->assertSame(0, SubscriptionReminder::query()->count());

        Artisan::call('subscriptions:process-reminders');
        $reminder = SubscriptionReminder::query()->first();
        $this->assertNotNull($reminder);
        $this->assertSame(SubscriptionReminder::STATUS_DETECTED, $reminder->status);
        $this->assertNull($reminder->sent_at);
        Mail::assertNothingSent();
        $this->assertSame($before, $this->subscriptionBusinessSnapshot($subscription));

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $second = json_decode(Artisan::output(), true);
        $this->assertSame(0, $second['newly_detected']);
        $this->assertSame(1, $second['already_recorded']);
        $this->assertSame(0, $second['sent']);
        Mail::assertNothingSent();
    }

    public function test_scenario_c_email_enabled_without_admin_address_fails_without_retry(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', null);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $first = json_decode(Artisan::output(), true);

        $reminder = SubscriptionReminder::query()->first();
        $this->assertSame(1, $first['newly_detected']);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $reminder->status);
        $this->assertNull($reminder->sent_at);
        Mail::assertNothingSent();

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $second = json_decode(Artisan::output(), true);
        $this->assertSame(0, $second['newly_detected']);
        $this->assertSame(1, $second['ignored']);
        Mail::assertNothingSent();
    }

    public function test_scenario_d_email_configured_generates_safe_message_via_mail_fake(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'preprod-reminders@example.test');
        Carbon::setTestNow('2026-10-24 12:00:00');
        $subscription = $this->makeSubscriptionAtThreshold(7, amount: 22000);

        Artisan::call('subscriptions:process-reminders');

        Mail::assertSent(SubscriptionReminderMail::class, function (SubscriptionReminderMail $mail) use ($subscription) {
            $html = strtolower($mail->render());

            return $mail->hasTo('preprod-reminders@example.test')
                && $mail->envelope()->subject === 'Échéance d’abonnement dans 7 jours'
                && str_contains($html, '22 000 xof')
                && ! str_contains($html, 'password')
                && ! str_contains($html, 'wave')
                && ! str_contains($html, 'database_password');
        });

        $reminder = SubscriptionReminder::query()->first();
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->status);
        $this->assertNotNull($reminder->sent_at);
        $this->assertSame($subscription->id, $reminder->subscription_id);
    }

    public function test_scenario_f_idempotence_after_successful_email(): void
    {
        $this->enableFullEmailPipeline();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders');
        $sentAt = SubscriptionReminder::query()->value('sent_at');

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $run2 = json_decode(Artisan::output(), true);
        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $run3 = json_decode(Artisan::output(), true);

        Mail::assertSent(SubscriptionReminderMail::class, 1);
        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertSame($sentAt?->format('Y-m-d H:i:s'), SubscriptionReminder::query()->value('sent_at')?->format('Y-m-d H:i:s'));
        $this->assertSame(0, $run2['sent']);
        $this->assertSame(1, $run2['already_recorded']);
        $this->assertSame($run2['already_recorded'], $run3['already_recorded']);
    }

    public function test_scenario_g_dry_run_with_email_enabled_does_not_mutate(): void
    {
        $this->enableFullEmailPipeline();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->makeSubscriptionAtThreshold(7);

        Artisan::call('subscriptions:process-reminders', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, SubscriptionReminder::query()->count());
        Mail::assertNothingSent();
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringContainsString('central_admin', $output);
    }

    public function test_scenario_h_four_thresholds_titles_and_amounts(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);

        $cases = [
            7 => ['now' => '2026-10-24 12:00:00', 'end' => '2026-10-31 23:59:59', 'title' => 'Échéance d’abonnement dans 7 jours'],
            3 => ['now' => '2026-10-28 12:00:00', 'end' => '2026-10-31 23:59:59', 'title' => 'Échéance d’abonnement dans 3 jours'],
            1 => ['now' => '2026-10-30 12:00:00', 'end' => '2026-10-31 23:59:59', 'title' => 'Échéance d’abonnement demain'],
            0 => ['now' => '2026-10-31 12:00:00', 'end' => '2026-10-31 23:59:59', 'title' => 'Échéance d’abonnement aujourd’hui'],
        ];

        $composer = app(SubscriptionReminderNotificationComposer::class);

        foreach ($cases as $threshold => $case) {
            Carbon::setTestNow($case['now']);
            $subscription = $this->makeSubscriptionWithPeriodEnd($case['end'], amount: 18000 + $threshold);

            Artisan::call('subscriptions:process-reminders', [
                '--subscription' => $subscription->id,
            ]);

            $reminder = SubscriptionReminder::query()->where('subscription_id', $subscription->id)->first();
            $this->assertNotNull($reminder, 'threshold '.$threshold);
            $this->assertSame($threshold, $reminder->threshold_days);

            $data = $composer->compose($reminder);
            $this->assertSame($case['title'], $data->title);
            $this->assertSame(18000 + $threshold, $data->amount);
            $this->assertSame('XOF', $data->currency);
            $this->assertSame('2026-10-31 23:59:59', Carbon::parse($data->current_period_end)->format('Y-m-d H:i:s'));
        }
    }

    public function test_scenario_i_terminal_states_respect_task_285(): void
    {
        $this->enableFullEmailPipeline();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $service = app(SubscriptionReminderService::class);

        $sentSub = $this->makeSubscriptionAtThreshold(7, suffix: 'sent');
        $sentRecord = $service->recordDetectedReminder($sentSub, 7);
        $service->markAsSent($sentRecord['reminder']);

        $failedSub = $this->makeSubscriptionAtThreshold(7, suffix: 'failed');
        $failedRecord = $service->recordDetectedReminder($failedSub, 7);
        $service->markAsFailed($failedRecord['reminder'], 'preprod');

        $detectedSub = $this->makeSubscriptionAtThreshold(7, suffix: 'detected');
        $service->recordDetectedReminder($detectedSub, 7);

        Artisan::call('subscriptions:process-reminders', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $payload['sent']);
        $this->assertGreaterThanOrEqual(3, $payload['already_recorded']);
        Mail::assertNothingSent();
    }

    public function test_scenario_j_isolation_from_automatic_credit_renewal(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $subscription = $this->makeSubscriptionAtThreshold(7);
        $this->makePaidCreditPayment($subscription);

        $periodBefore = $subscription->fresh()->current_period_end?->format('Y-m-d H:i:s');
        $consumptionBefore = SubscriptionPaymentConsumption::query()->count();
        $paymentBefore = Payment::query()->count();

        Artisan::call('subscriptions:process-reminders');
        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertSame($periodBefore, $subscription->fresh()->current_period_end?->format('Y-m-d H:i:s'));
        $this->assertSame($consumptionBefore, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame($paymentBefore, Payment::query()->count());

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $this->assertSame(0, SubscriptionReminder::query()->where('subscription_id', '!=', $subscription->id)->count());
    }

    public function test_scenario_k_isolation_from_lifecycle(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $eligible = $this->makeSubscriptionWithPeriodEnd('2026-10-31 23:59:59', suffix: 'lifecycle-eligible');
        Artisan::call('subscriptions:process-reminders', ['--subscription' => $eligible->id]);

        Carbon::setTestNow('2026-11-05 12:00:00');
        $expired = $this->makeSubscriptionWithPeriodEnd('2026-10-20 23:59:59', suffix: 'lifecycle-expired');

        $eligible->refresh();
        $lifecycleSnapshot = [
            'grace_period_ends_at' => $eligible->grace_period_ends_at?->format('Y-m-d H:i:s'),
            'suspended_at' => $eligible->suspended_at?->format('Y-m-d H:i:s'),
            'terminated_at' => $eligible->terminated_at?->format('Y-m-d H:i:s'),
            'status' => $eligible->status,
        ];

        Artisan::call('subscriptions:process-reminders', ['--subscription' => $expired->id]);
        Artisan::call('subscriptions:process-reminders');

        $eligible->refresh();
        $this->assertSame($lifecycleSnapshot['grace_period_ends_at'], $eligible->grace_period_ends_at?->format('Y-m-d H:i:s'));
        $this->assertSame($lifecycleSnapshot['suspended_at'], $eligible->suspended_at?->format('Y-m-d H:i:s'));
        $this->assertSame($lifecycleSnapshot['terminated_at'], $eligible->terminated_at?->format('Y-m-d H:i:s'));
        $this->assertSame($lifecycleSnapshot['status'], $eligible->status);
        $this->assertSame(0, SubscriptionReminder::query()->where('subscription_id', $expired->id)->count());
    }

    public function test_scenario_l_filters_and_json_outputs(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', false);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $target = $this->makeSubscriptionAtThreshold(7, suffix: 'target');
        $other = $this->makeSubscriptionAtThreshold(7, suffix: 'other');

        Artisan::call('subscriptions:process-reminders', [
            '--installation' => $target->installation_id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['newly_detected']);
        $this->assertSame(1, SubscriptionReminder::query()->count());
        $this->assertSame($target->id, SubscriptionReminder::query()->value('subscription_id'));

        Artisan::call('subscriptions:process-reminders', [
            '--subscription' => $other->id,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $dryJson = json_decode(Artisan::output(), true);
        $this->assertTrue($dryJson['dry_run']);
        $this->assertSame(1, $dryJson['newly_detected']);
        $this->assertSame(1, SubscriptionReminder::query()->count());
    }

    public function test_scenario_m_scheduler_entries_for_reminder_pipeline(): void
    {
        $lifecycle = $this->findScheduleEvent('subscriptions:sync-lifecycle');
        $renew = $this->findScheduleEvent('subscriptions:renew-with-credit');
        $reminders = $this->findScheduleEvent('subscriptions:process-reminders');

        $this->assertNotNull($lifecycle);
        $this->assertNotNull($renew);
        $this->assertNotNull($reminders);
        $this->assertSame('0 0 * * *', $reminders->expression);
        $this->assertTrue($reminders->withoutOverlapping);
        $this->assertSame('UTC', Config::get('app.timezone'));
    }

    public function test_repository_defaults_remain_disabled_for_production_safety(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SUBSCRIPTION_REMINDERS_ENABLED=false', $example);
        $this->assertStringContainsString('SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED=false', $example);
        $this->assertStringContainsString('SUBSCRIPTION_REMINDER_NOTIFICATION_CHANNEL=prepared', $example);
    }

    private function enableFullEmailPipeline(): void
    {
        Config::set('subscriptions.subscription_reminders.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'email');
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'preprod-reminders@example.test');
    }

    private function makeSubscriptionAtThreshold(int $threshold, string $suffix = 'default', int $amount = 15000): Subscription
    {
        $periodEnd = '2026-10-31 23:59:59';
        $now = match ($threshold) {
            7 => '2026-10-24 12:00:00',
            3 => '2026-10-28 12:00:00',
            1 => '2026-10-30 12:00:00',
            0 => '2026-10-31 12:00:00',
            default => '2026-10-24 12:00:00',
        };

        if (Carbon::getTestNow() === null) {
            Carbon::setTestNow($now);
        }

        return $this->makeSubscriptionWithPeriodEnd($periodEnd, Subscription::STATUS_ACTIVE, $suffix, $amount);
    }

    private function makeSubscriptionWithPeriodEnd(
        string $periodEnd,
        string $status = Subscription::STATUS_ACTIVE,
        string $suffix = 'default',
        int $amount = 15000,
    ): Subscription {
        $client = Client::query()->create([
            'company_name' => 'Preprod Client '.$suffix,
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation '.$suffix,
            'subdomain' => 'preprod288-'.$suffix.'-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => $amount,
            'currency' => 'XOF',
            'status' => $status,
            'starts_at' => '2026-10-01 00:00:00',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => $periodEnd,
        ]);
    }

    private function makePaidCreditPayment(Subscription $subscription): Payment
    {
        return Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 1,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => 2,
        ]);
    }

    /**
     * @return array{status: string, current_period_start: ?string, current_period_end: ?string, amount: int}
     */
    private function subscriptionBusinessSnapshot(Subscription $subscription): array
    {
        $fresh = $subscription->fresh();

        return [
            'status' => (string) $fresh->status,
            'current_period_start' => $fresh->current_period_start?->format('Y-m-d H:i:s'),
            'current_period_end' => $fresh->current_period_end?->format('Y-m-d H:i:s'),
            'amount' => (int) $fresh->amount,
        ];
    }

    private function findScheduleEvent(string $needle): ?Event
    {
        foreach (Schedule::events() as $event) {
            $command = (string) ($event->command ?? '');

            if (str_contains($command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
