<?php

namespace Tests\Feature;

use App\Mail\SubscriptionReminderMail;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\EmailSubscriptionReminderChannel;
use App\Notifications\Channels\PreparedSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderRecipient;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderNotificationComposer;
use App\Services\SubscriptionReminderNotificationSender;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionReminderEmailChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.timezone', 'UTC');
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_email_is_sent_to_configured_central_admin(): void
    {
        Mail::fake();
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'admin-central@example.test');

        $data = $this->composeForThreshold(7);

        $result = app(EmailSubscriptionReminderChannel::class)->send($data);

        $this->assertSame(SubscriptionReminderSendResult::STATUS_PREPARED, $result->status);
        Mail::assertSent(SubscriptionReminderMail::class, function (SubscriptionReminderMail $mail) {
            return $mail->hasTo('admin-central@example.test');
        });
    }

    public function test_email_subject_matches_dto_title_for_thresholds(): void
    {
        Mail::fake();
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'admin@example.test');

        $titles = [
            7 => 'Échéance d’abonnement dans 7 jours',
            3 => 'Échéance d’abonnement dans 3 jours',
            1 => 'Échéance d’abonnement demain',
            0 => 'Échéance d’abonnement aujourd’hui',
        ];

        foreach ($titles as $threshold => $title) {
            $data = $this->composeForThreshold($threshold);
            app(EmailSubscriptionReminderChannel::class)->send($data);

            Mail::assertSent(SubscriptionReminderMail::class, function (SubscriptionReminderMail $mail) use ($title) {
                return $mail->envelope()->subject === $title;
            });
        }
    }

    public function test_email_body_contains_installation_amount_and_period(): void
    {
        Mail::fake();
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'admin@example.test');

        $subscription = $this->makeSubscription([
            'amount' => 22000,
            'installation_name' => 'Boutique Email',
        ]);
        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 1,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 99999,
            'credit_months_purchased' => 1,
        ]);

        $data = $this->composeForSubscription($subscription, 3);

        app(EmailSubscriptionReminderChannel::class)->send($data);

        Mail::assertSent(SubscriptionReminderMail::class, function (SubscriptionReminderMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'Boutique Email')
                && str_contains($html, '22 000 XOF')
                && str_contains($html, '31/10/2026')
                && str_contains($html, 'Il reste 3 jours');
        });
    }

    public function test_email_does_not_contain_sensitive_or_payment_links(): void
    {
        Mail::fake();
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'admin@example.test');

        $data = $this->composeForThreshold(7);
        app(EmailSubscriptionReminderChannel::class)->send($data);

        Mail::assertSent(SubscriptionReminderMail::class, function (SubscriptionReminderMail $mail) {
            $html = strtolower($mail->render());

            return ! str_contains($html, 'password')
                && ! str_contains($html, 'wave')
                && ! str_contains($html, 'orange money')
                && ! str_contains($html, 'database_password')
                && ! str_contains($html, 'token');
        });
    }

    public function test_missing_admin_email_returns_unavailable_without_sending(): void
    {
        Mail::fake();
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', null);

        $result = app(EmailSubscriptionReminderChannel::class)->send($this->composeForThreshold(7));

        $this->assertSame(SubscriptionReminderSendResult::STATUS_UNAVAILABLE, $result->status);
        $this->assertSame(
            EmailSubscriptionReminderChannel::REASON_CENTRAL_ADMIN_EMAIL_NOT_CONFIGURED,
            $result->message,
        );
        Mail::assertNothingSent();
    }

    public function test_prepared_channel_remains_available(): void
    {
        $result = app(PreparedSubscriptionReminderChannel::class)->send($this->composeForThreshold(7));

        $this->assertSame(SubscriptionReminderSendResult::STATUS_PREPARED, $result->status);
    }

    public function test_unknown_channel_is_rejected_by_sender(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
        Config::set('subscriptions.subscription_reminder_notifications.channel', 'push');

        $this->expectException(\App\Exceptions\Subscription\SubscriptionReminderNotificationException::class);

        app(SubscriptionReminderNotificationSender::class)->send($this->makeReminder(7));
    }

    public function test_mail_exception_returns_error_status(): void
    {
        Config::set('subscriptions.subscription_reminder_notifications.central_admin_email', 'admin@example.test');

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP indisponible'));

        $result = app(EmailSubscriptionReminderChannel::class)->send($this->composeForThreshold(7));

        $this->assertSame(SubscriptionReminderSendResult::STATUS_ERROR, $result->status);
    }

    private function composeForThreshold(int $threshold): \App\Notifications\SubscriptionReminderNotificationData
    {
        return $this->composeForSubscription($this->makeSubscription(), $threshold);
    }

    private function composeForSubscription(
        Subscription $subscription,
        int $threshold,
    ): \App\Notifications\SubscriptionReminderNotificationData {
        $reminder = $this->makeReminderForSubscription($subscription, $threshold);

        return app(SubscriptionReminderNotificationComposer::class)->compose($reminder);
    }

    private function makeReminder(int $threshold): SubscriptionReminder
    {
        return $this->makeReminderForSubscription($this->makeSubscription(), $threshold);
    }

    private function makeReminderForSubscription(Subscription $subscription, int $threshold): SubscriptionReminder
    {
        $service = app(SubscriptionReminderService::class);
        $scheduledFor = $service->resolveScheduledFor($subscription, $threshold);

        return SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => $threshold,
            'scheduled_for' => $scheduledFor,
            'detected_at' => '2026-10-24 12:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $installationName = $attributes['installation_name'] ?? 'Installation Email';
        unset($attributes['installation_name']);

        $client = Client::query()->create([
            'company_name' => 'Client Email Channel',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => $installationName,
            'subdomain' => 'email286-'.uniqid(),
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
