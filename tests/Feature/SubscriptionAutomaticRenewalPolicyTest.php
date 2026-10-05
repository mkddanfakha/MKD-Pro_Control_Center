<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Services\InstallationAccessService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Politique renouvellement automatique vs consommation explicite (TASK 271).
 *
 * Lifecycle détaillé : SubscriptionLifecycleSyncTest, SyncSubscriptionLifecycleCommandTest.
 * Consommation / grace / suspended : SubscriptionCreditConsumptionHttpTest.
 */
class SubscriptionAutomaticRenewalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $subscriptionService;

    private InstallationAccessService $accessService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionService = app(SubscriptionService::class);
        $this->accessService = app(InstallationAccessService::class);
    }

    public function test_expired_active_subscription_with_credit_moves_to_grace_without_consuming_credit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->makePaidPayment($subscription, 45000, 3);

        $periodStartBefore = $subscription->current_period_start->format('Y-m-d H:i:s');
        $periodEndBefore = $subscription->current_period_end->format('Y-m-d H:i:s');

        $synced = $this->subscriptionService->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-02 12:00:00'),
        );

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $synced->status);
        $this->assertSame($periodStartBefore, $synced->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame($periodEndBefore, $synced->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(3, Payment::query()->first()->remainingCreditMonths());
    }

    public function test_scheduler_does_not_consume_credit_when_period_expires(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->travelTo('2026-11-02 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->status);
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_terminated_subscription_with_credit_is_ignored_by_scheduler(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        $this->travelTo('2026-12-01 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(1, Payment::query()->first()->remainingCreditMonths());
    }

    public function test_explicit_consumption_after_grace_reactivates_without_scheduler_re_suspending(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 15000, 1);

        $this->subscriptionService->consumeCreditFromPayment($payment);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));

        $this->travelTo('2026-11-15 12:00:00');
        Artisan::call('subscriptions:sync-lifecycle');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_access_is_date_aware_for_active_and_grace_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $installation = Installation::query()->findOrFail($subscription->installation_id);

        $this->travelTo('2026-10-15 12:00:00');
        $this->assertSame('accessible', $this->accessService->accessStatus($installation));

        $this->travelTo('2026-11-05 12:00:00');
        $subscription->update([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->assertSame('accessible', $this->accessService->accessStatus($installation->fresh()));

        $this->travelTo('2026-11-08 12:00:00');
        $this->assertSame('suspended', $this->accessService->accessStatus($installation->fresh()));
    }

    public function test_three_month_payment_requires_three_distinct_consumptions_for_three_periods(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        for ($i = 0; $i < 3; $i++) {
            $this->subscriptionService->consumeCreditFromPayment($payment->fresh());
        }

        $consumptions = SubscriptionPaymentConsumption::query()->orderBy('id')->get();
        $this->assertCount(3, $consumptions);

        $periods = $consumptions->map(fn ($c) => $c->period_start->format('Y-m-d').'..'.$c->period_end->format('Y-m-d'))->all();
        $this->assertSame([
            '2026-11-01..2026-11-30',
            '2026-12-01..2026-12-31',
            '2027-01-01..2027-01-31',
        ], $periods);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client Policy',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'pol-'.uniqid(),
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

    private function makePaidPayment(Subscription $subscription, int $amount, int $months): Payment
    {
        return Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => (int) $subscription->amount,
            'credit_months_purchased' => $months,
        ]);
    }
}
