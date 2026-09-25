<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentIndexConsumptionCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_zero_consumptions_count_for_payment_without_consumption(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Payments/Index')
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.consumptions_count', 0));
    }

    public function test_index_exposes_consumptions_count_of_one(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription);

        $this->createConsumption($payment, $subscription, '2026-11-01 00:00:00', '2026-11-30 23:59:59');

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.consumptions_count', 1));
    }

    public function test_index_exposes_consumptions_count_of_three(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = $this->makePaidPayment($subscription, ['amount' => 45000, 'credit_months_purchased' => 3]);

        foreach ([
            ['2026-10-01 00:00:00', '2026-10-31 23:59:59'],
            ['2026-11-01 00:00:00', '2026-11-30 23:59:59'],
            ['2026-12-01 00:00:00', '2026-12-31 23:59:59'],
        ] as [$periodStart, $periodEnd]) {
            $this->createConsumption($payment, $subscription, $periodStart, $periodEnd);
        }

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.consumptions_count', 3));
    }

    public function test_index_assigns_distinct_consumptions_counts_per_payment(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $paymentWithoutConsumption = $this->makePaidPayment($subscription, [
            'amount' => 15000,
            'paid_at' => '2026-09-01 12:00:00',
        ]);

        $paymentWithOneConsumption = $this->makePaidPayment($subscription, [
            'amount' => 15000,
            'paid_at' => '2026-09-05 12:00:00',
        ]);
        $this->createConsumption($paymentWithOneConsumption, $subscription, '2026-10-01 00:00:00', '2026-10-31 23:59:59');

        $paymentWithTwoConsumptions = $this->makePaidPayment($subscription, [
            'amount' => 30000,
            'credit_months_purchased' => 2,
            'paid_at' => '2026-09-10 12:00:00',
        ]);
        $this->createConsumption($paymentWithTwoConsumptions, $subscription, '2026-11-01 00:00:00', '2026-11-30 23:59:59');
        $this->createConsumption($paymentWithTwoConsumptions, $subscription, '2026-12-01 00:00:00', '2026-12-31 23:59:59');

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (
                $paymentWithoutConsumption,
                $paymentWithOneConsumption,
                $paymentWithTwoConsumptions,
            ) {
                $page->component('Subscriptions/Payments/Index')
                    ->has('payments.data', 3);

                $countsById = collect($page->toArray()['props']['payments']['data'])
                    ->mapWithKeys(fn (array $row) => [$row['id'] => $row['consumptions_count']])
                    ->all();

                $this->assertSame(0, $countsById[$paymentWithoutConsumption->id]);
                $this->assertSame(1, $countsById[$paymentWithOneConsumption->id]);
                $this->assertSame(2, $countsById[$paymentWithTwoConsumptions->id]);
            });
    }

    private function createConsumption(
        Payment $payment,
        Subscription $subscription,
        string $periodStart,
        string $periodEnd,
    ): void {
        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'consumed_at' => $periodStart,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePaidPayment(Subscription $subscription, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Index Consommations',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Index',
            'subdomain' => 'index-cons-'.uniqid(),
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
