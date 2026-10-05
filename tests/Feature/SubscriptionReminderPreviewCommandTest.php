<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\SubscriptionReminder;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionReminderPreviewCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.timezone', 'UTC');
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    public function test_preview_detected_reminder(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_DETECTED);

        $exitCode = Artisan::call('subscriptions:reminder-preview', ['reminder' => $reminder->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Aperçu du rappel', $output);
        $this->assertStringContainsString('Échéance d’abonnement dans 3 jours', $output);
        $this->assertStringContainsString('AUCUNE notification n’a été envoyée.', $output);
    }

    public function test_preview_sent_reminder(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_SENT, '2026-10-24 09:00:00');

        Artisan::call('subscriptions:reminder-preview', ['reminder' => $reminder->id]);

        $this->assertStringContainsString('(sent)', Artisan::output());
        $this->assertSame(SubscriptionReminder::STATUS_SENT, $reminder->fresh()->status);
    }

    public function test_preview_failed_reminder(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_FAILED);

        Artisan::call('subscriptions:reminder-preview', ['reminder' => $reminder->id]);

        $this->assertStringContainsString('(failed)', Artisan::output());
        $this->assertSame(SubscriptionReminder::STATUS_FAILED, $reminder->fresh()->status);
    }

    public function test_json_output(): void
    {
        $reminder = $this->makeReminder(SubscriptionReminder::STATUS_DETECTED);

        Artisan::call('subscriptions:reminder-preview', [
            'reminder' => $reminder->id,
            '--json' => true,
        ]);

        $output = Artisan::output();
        preg_match('/\{.*\}/s', $output, $matches);
        $decoded = json_decode($matches[0] ?? '', true);

        $this->assertSame($reminder->id, $decoded['reminder_id']);
        $this->assertSame('central_admin', $decoded['recipient']['type']);
        $this->assertSame('subscription_expiry', $decoded['type']);
    }

    public function test_missing_reminder_returns_failure(): void
    {
        $exitCode = Artisan::call('subscriptions:reminder-preview', ['reminder' => 999999]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('introuvable', Artisan::output());
    }

    public function test_preview_has_no_side_effects(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription);
        $reminder = $this->makeReminderForSubscription($subscription, 7);

        $beforeReminder = $reminder->fresh()->toArray();
        $beforeSubscription = $subscription->fresh()->toArray();

        Artisan::call('subscriptions:reminder-preview', ['reminder' => $reminder->id]);

        $this->assertSame($beforeReminder, $reminder->fresh()->toArray());
        $this->assertSame($beforeSubscription, $subscription->fresh()->toArray());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    private function makeReminder(string $status, ?string $sentAt = null): SubscriptionReminder
    {
        return $this->makeReminderForSubscription($this->makeSubscription(), 3, $status, $sentAt);
    }

    private function makeReminderForSubscription(
        Subscription $subscription,
        int $threshold,
        string $status = SubscriptionReminder::STATUS_DETECTED,
        ?string $sentAt = null,
    ): SubscriptionReminder {
        $service = app(SubscriptionReminderService::class);
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

    private function makeSubscription(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Preview',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Boutique Test',
            'subdomain' => 'prev283-'.uniqid(),
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

    private function makePaidPayment(Subscription $subscription): Payment
    {
        return Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ]);
    }
}
