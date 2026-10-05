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
use App\Services\SubscriptionAutomaticCreditRenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionAutomaticCreditRenewalAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        Carbon::setTestNow('2026-11-05 12:00:00');
    }

    public function test_audit_shows_disabled_and_is_read_only(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);
        $before = $this->captureState($subscription);

        Artisan::call('subscriptions:automatic-renewal-audit');
        $output = Artisan::output();

        $this->assertStringContainsString('DISABLED', $output);
        $this->assertSame($before, $this->captureState($subscription->fresh()));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_audit_when_feature_enabled_still_read_only(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $before = $this->captureState($subscription);

        Artisan::call('subscriptions:automatic-renewal-audit');

        $this->assertStringContainsString('ENABLED', Artisan::output());
        $this->assertSame($before, $this->captureState($subscription->fresh()));
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.credit_consumed')->count());
    }

    public function test_expired_with_credit_is_eligible_and_matches_service(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $expected = app(SubscriptionAutomaticCreditRenewalService::class)->assessEligibility($subscription);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);
        $output = Artisan::output();

        $this->assertTrue($expected['eligible']);
        $this->assertStringContainsString('eligible=yes', $output);
        $this->assertStringContainsString('state=EXPIRED', $output);
    }

    public function test_future_period_is_not_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-12-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('state=FUTURE', $output);
        $this->assertStringContainsString('period_not_expired', $output);
    }

    public function test_current_period_is_not_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-11-01 00:00:00',
            'current_period_end' => '2026-11-30 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('state=CURRENT', $output);
        $this->assertStringContainsString('period_not_expired', $output);
    }

    public function test_expired_without_credit_reports_no_consumable_credit(): void
    {
        $subscription = $this->makeSubscription();

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);

        $this->assertStringContainsString('no_consumable_credit', Artisan::output());
    }

    public function test_grace_period_with_credit_is_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);

        $this->assertStringContainsString('eligible=yes', Artisan::output());
    }

    public function test_suspended_with_credit_is_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);

        $this->assertStringContainsString('eligible=yes', Artisan::output());
    }

    public function test_terminated_with_credit_is_not_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);

        $this->assertStringContainsString('subscription_terminated', Artisan::output());
    }

    public function test_multi_month_credit_is_reported_without_consumption(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 45000, 3);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);

        $this->assertStringContainsString('credit=3mo', Artisan::output());
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_installation_filter_limits_scope(): void
    {
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();

        $subA = $this->makeSubscription(['installation_id' => $installationA->id]);
        $this->makeSubscription(['installation_id' => $installationB->id]);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--installation' => $installationA->id]);

        $output = Artisan::output();
        $this->assertStringContainsString('#'.$subA->id, $output);
        $this->assertStringContainsString('Évalués       : 1', $output);
    }

    public function test_subscription_and_installation_filters_combine(): void
    {
        $installation = $this->makeInstallation();
        $otherInstallation = $this->makeInstallation();
        $match = $this->makeSubscription(['installation_id' => $installation->id]);
        $other = $this->makeSubscription(['installation_id' => $otherInstallation->id]);

        Artisan::call('subscriptions:automatic-renewal-audit', [
            '--installation' => $installation->id,
            '--subscription' => $match->id,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('#'.$match->id, $output);
        $this->assertStringNotContainsString('#'.$other->id.' inst', $output);
        $this->assertStringContainsString('Évalués       : 1', $output);
    }

    public function test_eligible_only_filters_output(): void
    {
        $eligible = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($eligible, 15000, 1);

        $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--eligible-only' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('#'.$eligible->id, $output);
        $this->assertStringContainsString('Éligibles     : 1', $output);
        $this->assertStringContainsString('Non éligibles : 0', $output);
    }

    public function test_json_output_is_structured(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', [
            '--subscription' => $subscription->id,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['automatic_renewal_enabled']);
        $this->assertSame(1, $decoded['evaluated']);
        $this->assertSame($subscription->id, $decoded['subscriptions'][0]['subscription_id']);
    }

    public function test_offer_version_and_amount_unchanged_after_audit(): void
    {
        $offerVersion = $this->makeOfferVersion();
        $subscription = $this->makeSubscription([
            'offer_version_id' => $offerVersion->id,
            'amount' => 15000,
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:automatic-renewal-audit', ['--subscription' => $subscription->id]);

        $subscription->refresh();
        $offerVersion->refresh();

        $this->assertSame(15000, (int) $subscription->amount);
        $this->assertSame($offerVersion->id, $subscription->offer_version_id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
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
            'company_name' => 'Client Audit 280',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation-'.uniqid(),
            'subdomain' => 'aud280-'.uniqid(),
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
            'code' => 'P-AUD-'.uniqid(),
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'O-AUD-'.uniqid(),
            'name' => 'Offer',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        return OfferVersion::query()->create([
            'offer_id' => $offer->id,
            'version' => 'v-aud-'.uniqid(),
            'code' => 'V-AUD-'.uniqid(),
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'status' => OfferVersion::STATUS_ACTIVE,
            'description' => 'Audit',
            'inclusions' => [],
            'limitations' => [],
            'exclusions' => [],
        ]);
    }
}
