<?php

namespace Tests\Unit;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    public function test_calculate_next_period_starts_on_first_of_october_and_ends_on_last_day_of_october(): void
    {
        $subscription = $this->makeSubscription();

        $period = $this->service->calculateNextPeriod(
            $subscription,
            Carbon::create(2026, 10, 1, 0, 0, 0),
        );

        $this->assertSame('2026-10-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_calculate_next_period_for_november_has_thirty_days(): void
    {
        $subscription = $this->makeSubscription();

        $period = $this->service->calculateNextPeriod(
            $subscription,
            Carbon::create(2026, 11, 1, 0, 0, 0),
        );

        $this->assertSame('2026-11-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_calculate_next_period_for_february_non_leap_year(): void
    {
        $subscription = $this->makeSubscription();

        $period = $this->service->calculateNextPeriod(
            $subscription,
            Carbon::create(2026, 2, 1, 0, 0, 0),
        );

        $this->assertSame('2026-02-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-28 23:59:59', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_calculate_next_period_for_february_leap_year(): void
    {
        $subscription = $this->makeSubscription();

        $period = $this->service->calculateNextPeriod(
            $subscription,
            Carbon::create(2024, 2, 1, 0, 0, 0),
        );

        $this->assertSame('2024-02-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-29 23:59:59', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_create_initial_period_from_starts_at(): void
    {
        $subscription = $this->makeSubscription([
            'starts_at' => '2026-10-01 00:00:00',
        ]);

        $updated = $this->service->createInitialPeriod($subscription);

        $this->assertSame('2026-10-01 00:00:00', $updated->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $updated->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_renew_with_paid_payment_advances_to_next_month(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $renewed = $this->service->renew($subscription, $payment);

        $this->assertSame('2026-11-01 00:00:00', $renewed->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $renewed->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::STATUS_ACTIVE, $renewed->status);
    }

    public function test_renew_with_pending_payment_is_rejected(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renew($subscription, $payment);
    }

    public function test_renew_with_failed_payment_is_rejected(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_FAILED,
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renew($subscription, $payment);
    }

    public function test_renew_on_terminated_subscription_is_rejected(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => now(),
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $this->expectException(SubscriptionRenewalException::class);

        $this->service->renew($subscription, $payment);
    }

    public function test_renew_rejects_payment_with_lower_amount_than_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 16000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('montant du paiement');

        $this->service->renew($subscription, $payment);
    }

    public function test_renew_rejects_payment_with_higher_amount_than_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 16000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('montant du paiement');

        $this->service->renew($subscription, $payment);
    }

    public function test_renew_rejects_payment_with_different_currency(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $this->expectException(SubscriptionRenewalException::class);
        $this->expectExceptionMessage('devise du paiement');

        $this->service->renew($subscription, $payment);
    }

    public function test_can_renew_from_payment_requires_matching_amount_and_currency(): void
    {
        $subscription = $this->makeSubscription([
            'amount' => 16000,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $mismatch = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $this->assertFalse($this->service->canRenewFromPayment($mismatch));

        $match = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 16000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $this->assertTrue($this->service->canRenewFromPayment($match));
    }

    public function test_renew_resets_grace_period_ends_at(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-05 23:59:59',
            'suspended_at' => '2026-11-01 12:00:00',
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
        ]);

        $renewed = $this->service->renew($subscription, $payment);

        $this->assertNull($renewed->grace_period_ends_at);
        $this->assertNull($renewed->suspended_at);
        $this->assertSame(Subscription::STATUS_ACTIVE, $renewed->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }
}
