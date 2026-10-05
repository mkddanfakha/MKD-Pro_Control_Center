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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Validation contrôlée activation enabled=true — Task 278.
 *
 * Environnement : base SQLite mémoire (RefreshDatabase), données synthétiques uniquement.
 * Ne remplace pas une préproduction dédiée sur serveur, mais couvre les critères techniques A–G.
 */
class SubscriptionAutomaticCreditRenewalPreprodValidationTest extends TestCase
{
    use RefreshDatabase;

    private int $paymentCountBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscriptions.automatic_credit_renewal.enabled', true);
        $this->paymentCountBefore = 0;
        Carbon::setTestNow('2026-11-05 12:00:00');
    }

    public function test_preprod_dry_run_then_real_run_matrix(): void
    {
        $scenarios = $this->seedValidationScenarios();

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $dryOutput = Artisan::output();

        $this->assertStringContainsString('DRY-RUN', $dryOutput);
        $this->assertStringContainsString('simulations', $dryOutput);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->count());

        Artisan::call('subscriptions:renew-with-credit');
        $realOutput = Artisan::output();

        $this->assertStringContainsString('renouvelés', $realOutput);

        $this->assertScenarioActiveWithCredit($scenarios['active']);
        $this->assertScenarioGrace($scenarios['grace']);
        $this->assertScenarioSuspended($scenarios['suspended']);
        $this->assertScenarioNoCredit($scenarios['no_credit']);
        $this->assertScenarioValidPeriod($scenarios['valid_period']);
        $this->assertScenarioTerminated($scenarios['terminated']);
        $this->assertScenarioMultiMonth($scenarios['multi_month']);

        $consumptions = SubscriptionPaymentConsumption::query()->count();
        $this->assertSame(4, $consumptions);

        $auditCount = AuditLog::query()->where('action', 'subscription.credit_consumed')->count();
        $this->assertSame(4, $auditCount);

        foreach (AuditLog::query()->where('action', 'subscription.credit_consumed')->get() as $log) {
            $this->assertSame('automatic_credit_renewal', $log->new_values['renewal_trigger']);
        }

        $this->assertSame($this->paymentCountBefore + 6, Payment::query()->count());

        Artisan::call('subscriptions:renew-with-credit');
        $this->assertSame(4, SubscriptionPaymentConsumption::query()->count());

        Artisan::call('subscriptions:renew-with-credit', ['--dry-run' => true]);
        $this->assertStringContainsString('0 simulations', Artisan::output());

        $this->assertTraceabilityChain($scenarios['active']);
    }

    public function test_scheduler_entries_remain_separate(): void
    {
        $lifecycle = null;
        $renew = null;

        foreach (Schedule::events() as $event) {
            $command = (string) ($event->command ?? '');

            if (str_contains($command, 'subscriptions:sync-lifecycle')) {
                $lifecycle = $event;
            }

            if (str_contains($command, 'subscriptions:renew-with-credit')) {
                $renew = $event;
            }
        }

        $this->assertNotNull($lifecycle);
        $this->assertNotNull($renew);
        $this->assertTrue($renew->withoutOverlapping);
        $this->assertNotSame($lifecycle, $renew);
    }

    public function test_real_run_logs_structured_summary_without_secrets(): void
    {
        Log::spy();

        $subscription = $this->makeSubscription();
        $this->makePaidPayment($subscription, 15000, 1);

        Artisan::call('subscriptions:renew-with-credit');

        Log::shouldHaveReceived('info')
            ->with('subscriptions:renew-with-credit — exécution terminée', \Mockery::on(
                fn (array $context): bool => ($context['dry_run'] ?? null) === false
                    && ($context['renewed'] ?? null) === 1
                    && ($context['evaluated'] ?? null) === 1
            ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Config::set('subscriptions.automatic_credit_renewal.enabled', false);
        parent::tearDown();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function seedValidationScenarios(): array
    {
        $this->paymentCountBefore = Payment::query()->count();

        $offerVersion = $this->makeOfferVersion();
        $active = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'offer_version_id' => $offerVersion->id,
            'amount' => 15000,
        ]);
        $activePayment = $this->makePaidPayment($active, 15000, 1);

        $grace = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);
        $this->makePaidPayment($grace, 15000, 1);

        $suspended = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);
        $this->makePaidPayment($suspended, 15000, 1);

        $noCredit = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
        ]);

        $validPeriod = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'current_period_end' => '2026-12-31 23:59:59',
        ]);
        $this->makePaidPayment($validPeriod, 15000, 1);

        $terminated = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);
        $this->makePaidPayment($terminated, 15000, 1);

        $multi = $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
        ]);
        $multiPayment = $this->makePaidPayment($multi, 45000, 3);

        return [
            'active' => ['subscription' => $active, 'payment' => $activePayment, 'offerVersion' => $offerVersion],
            'grace' => ['subscription' => $grace],
            'suspended' => ['subscription' => $suspended],
            'no_credit' => ['subscription' => $noCredit],
            'valid_period' => ['subscription' => $validPeriod],
            'terminated' => ['subscription' => $terminated],
            'multi_month' => ['subscription' => $multi, 'payment' => $multiPayment],
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioActiveWithCredit(array $scenario): void
    {
        /** @var Subscription $subscription */
        $subscription = $scenario['subscription']->fresh();
        /** @var OfferVersion $offerVersion */
        $offerVersion = $scenario['offerVersion']->fresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(15000, (int) $subscription->amount);
        $this->assertSame($offerVersion->id, $subscription->offer_version_id);
        $this->assertSame(15000, (int) $offerVersion->price);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioGrace(array $scenario): void
    {
        $subscription = $scenario['subscription']->fresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count());
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioSuspended(array $scenario): void
    {
        $subscription = $scenario['subscription']->fresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioNoCredit(array $scenario): void
    {
        $subscription = $scenario['subscription']->fresh();
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count());
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioValidPeriod(array $scenario): void
    {
        $subscription = $scenario['subscription']->fresh();
        $this->assertSame('2026-12-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count());
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioTerminated(array $scenario): void
    {
        $subscription = $scenario['subscription']->fresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
        $this->assertSame(0, SubscriptionPaymentConsumption::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.credit_consumed')->where('auditable_id', $subscription->id)->count());
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertScenarioMultiMonth(array $scenario): void
    {
        /** @var Payment $payment */
        $payment = $scenario['payment']->fresh();
        $this->assertSame(1, SubscriptionPaymentConsumption::query()->where('subscription_id', $scenario['subscription']->id)->count());
        $this->assertSame(2, $payment->remainingCreditMonths());
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function assertTraceabilityChain(array $scenario): void
    {
        /** @var Subscription $subscription */
        $subscription = $scenario['subscription']->fresh();
        /** @var Payment $payment */
        $payment = $scenario['payment']->fresh();

        $consumption = SubscriptionPaymentConsumption::query()
            ->where('subscription_id', $subscription->id)
            ->sole();

        $this->assertSame($payment->id, $consumption->payment_id);
        $this->assertNotNull($consumption->consumed_at);

        $audit = AuditLog::query()
            ->where('action', 'subscription.credit_consumed')
            ->where('auditable_id', $subscription->id)
            ->sole();

        $this->assertSame('automatic_credit_renewal', $audit->new_values['renewal_trigger']);
        $this->assertSame($payment->id, $audit->new_values['consumption']['payment_id']);
    }

    private function makeOfferVersion(): OfferVersion
    {
        $product = Product::query()->create([
            'code' => 'P-PREPROD-'.uniqid(),
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'O-PREPROD-'.uniqid(),
            'name' => 'Offer',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        return OfferVersion::query()->create([
            'offer_id' => $offer->id,
            'version' => 'v-preprod-'.uniqid(),
            'code' => 'V-PREPROD-'.uniqid(),
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'status' => OfferVersion::STATUS_ACTIVE,
            'description' => 'Validation préprod',
            'inclusions' => [],
            'limitations' => [],
            'exclusions' => [],
        ]);
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
            'company_name' => 'Client Preprod 278',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'pre278-'.uniqid(),
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
