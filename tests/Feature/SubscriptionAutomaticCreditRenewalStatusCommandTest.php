<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionAutomaticCreditRenewalStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        Config::set('app.timezone', 'UTC');
    }

    public function test_status_command_when_disabled_is_readable(): void
    {
        $exitCode = Artisan::call('subscriptions:automatic-renewal-status');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Automatic   : DISABLED', $output);
        $this->assertStringContainsString('Dry-run     : AVAILABLE', $output);
    }

    public function test_status_command_when_enabled_shows_enabled(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        Artisan::call('subscriptions:automatic-renewal-status');

        $this->assertStringContainsString('Automatic   : ENABLED', Artisan::output());
    }

    public function test_status_command_displays_app_env_and_timezone(): void
    {
        Config::set('app.env', 'testing');

        Artisan::call('subscriptions:automatic-renewal-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Environment : testing', $output);
        $this->assertStringContainsString('Timezone    : UTC', $output);
    }

    public function test_status_command_is_strictly_read_only(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $consumptionsBefore = SubscriptionPaymentConsumption::query()->count();
        $paymentsBefore = Payment::query()->count();
        $auditsBefore = AuditLog::query()->count();

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:automatic-renewal-status');

        $this->assertSame($consumptionsBefore, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame($auditsBefore, AuditLog::query()->count());
        $subscription->refresh();
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_status_command_shows_scheduler_daily_for_renew_command(): void
    {
        Artisan::call('subscriptions:automatic-renewal-status');

        $this->assertStringContainsString('Scheduler   : DAILY', Artisan::output());
    }

    public function test_production_with_enabled_shows_explicit_warning(): void
    {
        Config::set('app.env', 'production');
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        Artisan::call('subscriptions:automatic-renewal-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Automatic   : ENABLED', $output);
        $this->assertStringContainsString('ATTENTION : production', $output);
    }

    public function test_local_with_disabled_does_not_show_production_active_warning(): void
    {
        Config::set('app.env', 'local');
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);

        Artisan::call('subscriptions:automatic-renewal-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Automatic   : DISABLED', $output);
        $this->assertStringNotContainsString('production avec renouvellement automatique ACTIVÉ', $output);
    }

    public function test_renew_command_in_production_when_enabled_shows_operational_warning(): void
    {
        Config::set('app.env', 'production');
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('APP_ENV=production', $output);
        $this->assertStringContainsString('dry-run', strtolower($output));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        Config::set('app.env', 'testing');
        parent::tearDown();
    }

    private function makeSubscription(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Status 279',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'stat279-'.uniqid(),
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
}
