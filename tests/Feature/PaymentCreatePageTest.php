<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCreatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_payment_create_component(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->get(route('payments.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Subscriptions/Payments/Create')
            ->has('subscriptions', 1)
            ->where('subscriptions.0.id', $subscription->id));
    }

    public function test_store_does_not_accept_client_supplied_credit_fields_in_payload(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $response = $this->actingAs($user)->post(route('payments.store'), [
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-15 12:00:00',
            'payment_method' => 'wave',
            'reference' => 'REF-CREATE-UX',
            'monthly_unit_amount' => 1,
            'credit_months_purchased' => 99,
        ]);

        $response->assertSessionHasErrors(['monthly_unit_amount', 'credit_months_purchased']);
    }

    private function makeSubscription(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Create UX',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Create UX',
            'subdomain' => 'create-ux-'.uniqid(),
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
