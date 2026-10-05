<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\OfferVersion;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CommercialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionOfferVersionRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_may_have_null_offer_version_id(): void
    {
        $subscription = $this->makeSubscription();

        $this->assertNull($subscription->offer_version_id);
        $this->assertNull($subscription->offerVersion);
    }

    public function test_subscription_can_reference_offer_version(): void
    {
        $this->seed(CommercialCatalogSeeder::class);
        $offerVersion = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();

        $subscription = $this->makeSubscription(['offer_version_id' => $offerVersion->id]);

        $this->assertSame($offerVersion->id, $subscription->offerVersion->id);
    }

    public function test_offer_version_has_many_subscriptions(): void
    {
        $this->seed(CommercialCatalogSeeder::class);
        $offerVersion = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();

        $first = $this->makeSubscription(['offer_version_id' => $offerVersion->id]);
        $secondInstallation = $this->makeInstallation();
        $second = Subscription::query()->create([
            'installation_id' => $secondInstallation->id,
            'offer_version_id' => $offerVersion->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => now(),
        ]);

        $ids = $offerVersion->subscriptions()->pluck('id')->all();

        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
    }

    public function test_store_requires_offer_version_id(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'currency' => 'XOF',
            'amount' => 15000,
        ]);

        $response->assertSessionHasErrors('offer_version_id');
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_legacy_subscription_without_offer_version_remains_valid_at_model_level(): void
    {
        $subscription = $this->makeSubscription();

        $this->assertNull($subscription->offer_version_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_subscription_amount_and_periods_unchanged_when_offer_version_set(): void
    {
        $this->seed(CommercialCatalogSeeder::class);
        $offerVersion = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();

        $subscription = Subscription::query()->create([
            'installation_id' => $this->makeInstallation()->id,
            'offer_version_id' => $offerVersion->id,
            'amount' => 18000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-03-01 00:00:00',
            'current_period_start' => '2026-03-01 00:00:00',
            'current_period_end' => '2026-03-31 23:59:59',
        ]);

        $this->assertSame(18000, (int) $subscription->amount);
        $this->assertSame('2026-03-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame(15000, (int) $offerVersion->price);
    }

    public function test_payment_logic_unchanged_without_offer_version_on_subscription(): void
    {
        $subscription = $this->makeSubscription();

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'monthly_unit_amount' => 15000,
            'currency' => 'XOF',
            'credit_months_purchased' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->assertSame(15000, (int) $payment->amount);
        $this->assertSame(1, (int) $payment->credit_months_purchased);
        $this->assertNull($subscription->fresh()->offer_version_id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'installation_id' => $this->makeInstallation()->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
