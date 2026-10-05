<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Services\SubscriptionAutomaticCreditRenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Préparation du renouvellement automatique par crédit — Task 272 (désactivé en production).
 */
class SubscriptionAutomaticCreditRenewalPreparationTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionAutomaticCreditRenewalService $automaticRenewal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->automaticRenewal = app(SubscriptionAutomaticCreditRenewalService::class);
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
    }

    public function test_feature_is_disabled_by_default(): void
    {
        $this->assertFalse(config('subscriptions.automatic_credit_renewal.enabled'));
        $this->assertFalse($this->automaticRenewal->isEnabled());
    }

    public function test_attempt_when_disabled_does_not_consume_credit(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $result = $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_DISABLED, $result['outcome']);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_assess_marks_non_expired_period_as_ineligible(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-10-15 12:00:00'),
        );

        $this->assertFalse($assessment['eligible']);
        $this->assertSame(
            SubscriptionAutomaticCreditRenewalService::REASON_PERIOD_NOT_EXPIRED,
            $assessment['reason'],
        );
    }

    public function test_assess_marks_expired_period_with_credit_as_eligible_for_future_automation(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 45000, 3);

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-11-05 12:00:00'),
        );

        $this->assertTrue($assessment['eligible']);
    }

    public function test_assess_marks_expired_period_without_credit_as_ineligible(): void
    {
        $subscription = $this->makeSubscription();

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-11-05 12:00:00'),
        );

        $this->assertFalse($assessment['eligible']);
        $this->assertSame(
            SubscriptionAutomaticCreditRenewalService::REASON_NO_CONSUMABLE_CREDIT,
            $assessment['reason'],
        );
    }

    public function test_assess_grace_period_expired_subscription_with_credit_as_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-11-05 12:00:00'),
        );

        $this->assertTrue($assessment['eligible']);
    }

    public function test_assess_suspended_subscription_with_credit_as_eligible_for_future_policy(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-11-10 12:00:00'),
        );

        $this->assertTrue($assessment['eligible']);
    }

    public function test_assess_terminated_subscription_is_never_eligible(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $assessment = $this->automaticRenewal->assessEligibility(
            $subscription,
            Carbon::parse('2026-12-01 12:00:00'),
        );

        $this->assertFalse($assessment['eligible']);
        $this->assertSame(SubscriptionAutomaticCreditRenewalService::REASON_TERMINATED, $assessment['reason']);

        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        $result = $this->automaticRenewal->attemptAutomaticRenewal($subscription);
        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_NOT_ELIGIBLE, $result['outcome']);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_when_enabled_attempt_uses_fifo_via_consume_next_credit(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $paymentA = $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-01 10:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1, [
            'paid_at' => '2026-09-05 10:00:00',
        ]);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $result = $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_CONSUMED, $result['outcome']);
        $this->assertSame($paymentA->id, $result['payment_id']);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_when_enabled_one_attempt_consumes_exactly_one_month(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 45000, 3);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $subscription->refresh();
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(2, Payment::query()->first()->remainingCreditMonths());
    }

    public function test_when_enabled_second_attempt_is_not_eligible_until_next_period_expires(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 30000, 2);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $first = $this->automaticRenewal->attemptAutomaticRenewal($subscription);
        $second = $this->automaticRenewal->attemptAutomaticRenewal($subscription->fresh());

        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_CONSUMED, $first['outcome']);
        $this->assertSame(SubscriptionAutomaticCreditRenewalService::OUTCOME_NOT_ELIGIBLE, $second['outcome']);
        $this->assertSame(
            SubscriptionAutomaticCreditRenewalService::REASON_PERIOD_NOT_EXPIRED,
            $second['reason'],
        );
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_when_enabled_automatic_consumption_records_audit_with_trigger_context(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->automaticRenewal->attemptAutomaticRenewal($subscription);

        $log = AuditLog::query()->where('action', 'subscription.credit_consumed')->sole();
        $this->assertSame('automatic_credit_renewal', $log->new_values['renewal_trigger']);
    }

    public function test_lifecycle_scheduler_still_does_not_consume_credit_after_preparation(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        $this->travelTo('2026-11-05 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
    }

    public function test_historical_payment_credit_unchanged_after_subscription_amount_change(): void
    {
        $subscription = $this->makeSubscription(['amount' => 15000]);
        $payment = $this->makePaidPayment($subscription, 30000, 2);

        $subscription->update(['amount' => 20000]);

        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        Carbon::setTestNow('2026-11-05 12:00:00');

        $this->automaticRenewal->attemptAutomaticRenewal($subscription->fresh());

        $payment->refresh();
        $this->assertSame(15000, (int) $payment->monthly_unit_amount);
        $this->assertSame(2, (int) $payment->credit_months_purchased);
        $this->assertSame(1, $payment->remainingCreditMonths());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Auto Renewal Prep',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'prep-'.uniqid(),
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePaidPayment(Subscription $subscription, int $amount, int $months, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ], $overrides));
    }
}
