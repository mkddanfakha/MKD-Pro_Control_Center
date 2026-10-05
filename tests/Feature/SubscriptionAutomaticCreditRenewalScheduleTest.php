<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Planification du renouvellement automatique par crédit — Task 275.
 *
 * Le scheduler Laravel exécute les commandes planifiées dans un sous-processus ;
 * les scénarios « enabled » appellent directement `subscriptions:renew-with-credit`
 * (même binaire que la planification). Le test `schedule:run` avec config désactivée
 * valide le chemin planificateur → commande sans consommation.
 */
class SubscriptionAutomaticCreditRenewalScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
    }

    public function test_renew_with_credit_command_is_registered_in_scheduler(): void
    {
        $event = $this->findRenewWithCreditScheduleEvent();

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_schedule_run_with_disabled_config_does_not_consume_credit(): void
    {
        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-02 00:00:00');

        Artisan::call('schedule:run');

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_scheduled_event_run_with_enabled_config_consumes_one_month(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_scheduled_event_does_not_renew_subscription_with_valid_period(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-10-15 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_scheduled_event_renews_grace_period_subscription_when_enabled(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
    }

    public function test_scheduled_event_renews_suspended_subscription_when_enabled(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-10 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    public function test_scheduled_event_does_not_process_terminated_subscription(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-12-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_scheduled_event_without_credit_leaves_lifecycle_path_available(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();

        Carbon::setTestNow('2026-11-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
    }

    public function test_scheduled_event_processes_multiple_subscriptions_when_enabled(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subA = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($subA, 15000, 1);

        $subB = $this->makeSubscription(['installation_id' => $this->makeInstallation()->id]);
        $this->makePaidPayment($subB, 15000, 1);

        Carbon::setTestNow('2026-11-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(2, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_two_scheduled_event_runs_do_not_double_consume_same_period(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 30000, 2);

        Carbon::setTestNow('2026-11-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();
        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_scheduled_event_consumes_at_most_one_month_from_multi_month_payment(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, 45000, 3);

        Carbon::setTestNow('2026-11-02 00:00:00');

        $this->runScheduledRenewWithCreditEvent();

        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame(2, $payment->fresh()->remainingCreditMonths());
    }

    public function test_lifecycle_then_renewal_order_is_consistent_when_credit_available(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-02 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');
        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->status);

        Artisan::call('subscriptions:renew-with-credit');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
    }

    public function test_renewal_then_lifecycle_order_is_consistent_when_credit_available(): void
    {
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Carbon::setTestNow('2026-11-02 00:00:00');

        Artisan::call('subscriptions:renew-with-credit');
        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());

        Artisan::call('subscriptions:sync-lifecycle');

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->count());
    }

    public function test_lifecycle_and_renewal_commands_are_both_scheduled_separately(): void
    {
        $lifecycle = $this->findScheduleEvent('subscriptions:sync-lifecycle');
        $renew = $this->findRenewWithCreditScheduleEvent();

        $this->assertNotNull($lifecycle);
        $this->assertNotNull($renew);
        $this->assertNotSame($lifecycle, $renew);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        parent::tearDown();
    }

    private function runScheduledRenewWithCreditEvent(): void
    {
        Artisan::call('subscriptions:renew-with-credit');
    }

    private function findRenewWithCreditScheduleEvent(): ?Event
    {
        return $this->findScheduleEvent('subscriptions:renew-with-credit');
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $installation = $this->makeInstallation();

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

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client Schedule 275',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'sched275-'.uniqid(),
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
}
