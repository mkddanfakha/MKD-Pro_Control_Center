<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Notifications\SubscriptionReminderRecipient;
use App\Services\SubscriptionReminderNotificationComposer;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderNotificationComposerTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionReminderNotificationComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.timezone', 'UTC');
        $this->composer = app(SubscriptionReminderNotificationComposer::class);
    }

    public function test_composes_seven_day_threshold(): void
    {
        $this->assertComposedThreshold(
            7,
            'Échéance d’abonnement dans 7 jours',
            'Il reste 7 jours avant l’échéance.',
        );
    }

    public function test_composes_three_day_threshold(): void
    {
        $this->assertComposedThreshold(
            3,
            'Échéance d’abonnement dans 3 jours',
            'Il reste 3 jours avant l’échéance.',
        );
    }

    public function test_composes_one_day_threshold(): void
    {
        $this->assertComposedThreshold(
            1,
            'Échéance d’abonnement demain',
            'Il reste 1 jour avant l’échéance.',
        );
    }

    public function test_composes_zero_day_threshold(): void
    {
        $this->assertComposedThreshold(
            0,
            'Échéance d’abonnement aujourd’hui',
            'Votre abonnement arrive à échéance aujourd’hui.',
        );
    }

    private function assertComposedThreshold(int $threshold, string $title, string $sentence): void
    {
        $reminder = $this->makeReminder($threshold, [
            'amount' => 15000,
            'installation_name' => 'Boutique Test',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $data = $this->composer->compose($reminder);

        $this->assertSame($title, $data->title);
        $this->assertStringContainsString('Boutique Test', $data->body);
        $this->assertStringContainsString('31/10/2026', $data->body);
        $this->assertStringContainsString('15 000 XOF', $data->body);
        $this->assertStringContainsString($sentence, $data->body);
        $this->assertSame($threshold, $data->threshold_days);
        $this->assertSame($threshold, $data->days_remaining);
    }

    public function test_uses_subscription_amount_not_offer_or_payment(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 22000,
            'currency' => 'XOF',
        ]);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 99999,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 88888,
            'credit_months_purchased' => 1,
        ]);

        $reminder = $this->makeReminderForSubscription($subscription, 3);

        $data = $this->composer->compose($reminder);

        $this->assertSame(22000, $data->amount);
        $this->assertStringContainsString('22 000 XOF', $data->body);
    }

    public function test_recipient_is_central_admin_without_user_id(): void
    {
        $data = $this->composer->compose($this->makeReminder(7));

        $this->assertSame(SubscriptionReminderRecipient::TYPE_CENTRAL_ADMIN, $data->recipient->type);
        $this->assertNull($data->recipient->id);
    }

    public function test_compose_does_not_modify_reminder_or_subscription(): void
    {
        $reminder = $this->makeReminder(7, [], SubscriptionReminder::STATUS_SENT, '2026-10-24 09:00:00');
        $subscription = $reminder->subscription;

        $reminderBefore = $reminder->fresh()->toArray();
        $subscriptionBefore = $subscription->fresh()->toArray();
        $paymentsBefore = Payment::query()->count();
        $consumptionsBefore = SubscriptionPaymentConsumption::query()->count();

        $this->composer->compose($reminder);

        $this->assertSame($reminderBefore, $reminder->fresh()->toArray());
        $this->assertSame($subscriptionBefore, $subscription->fresh()->toArray());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame($consumptionsBefore, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_detected_sent_and_failed_statuses_unchanged_after_compose(): void
    {
        foreach ([SubscriptionReminder::STATUS_DETECTED, SubscriptionReminder::STATUS_SENT, SubscriptionReminder::STATUS_FAILED] as $status) {
            $reminder = $this->makeReminder(3, [], $status);
            $this->composer->compose($reminder);
            $this->assertSame($status, $reminder->fresh()->status);
        }
    }

    /**
     * @param  array<string, mixed>  $subscriptionAttributes
     */
    private function makeReminder(
        int $threshold,
        array $subscriptionAttributes = [],
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        $subscription = $this->makeSubscription($subscriptionAttributes);

        return $this->makeReminderForSubscription($subscription, $threshold, $status, $sentAt);
    }

    private function makeReminderForSubscription(
        Subscription $subscription,
        int $threshold,
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        $service = app(SubscriptionReminderService::class);
        Carbon::setTestNow('2026-10-24 12:00:00');
        $scheduledFor = $service->resolveScheduledFor($subscription, $threshold);

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
        $installationName = $attributes['installation_name'] ?? 'Installation Composer';
        unset($attributes['installation_name']);

        $client = Client::query()->create([
            'company_name' => 'Client Composer',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => $installationName,
            'subdomain' => 'comp283-'.uniqid(),
            'status' => 'active',
        ]);

        $installationId = $attributes['installation_id'] ?? $installation->id;
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
}
