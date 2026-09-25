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

class PaymentEditCreditConsumptionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_exposes_consumptions_count_for_guard_ui(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'credit_months_purchased' => 1,
            'monthly_unit_amount' => 15000,
        ]);

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'consumed_at' => '2026-11-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.edit', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Payments/Edit')
                ->where('payment.consumptions_count', 1));
    }

    public function test_edit_vue_locks_financial_fields_when_consumptions_exist(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Payments/Edit.vue'));

        $this->assertStringContainsString('hasCreditConsumption', $contents);
        $this->assertStringContainsString('financialFieldsLocked', $contents);
        $this->assertStringContainsString('Ce paiement a déjà financé une ou plusieurs périodes', $contents);
        $this->assertStringContainsString('consumptions_count', $contents);
    }

    private function makeSubscription(): Subscription
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

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }
}
