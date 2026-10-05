<?php

namespace Tests\Feature;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionOfferSnapshot;
use App\Services\Commercial\OfferVersionPublicationService;
use Database\Seeders\CommercialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommercialCatalogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_offer_version_can_be_modified(): void
    {
        $version = $this->makeDraftVersion();

        $version->update(['price' => 16000]);

        $this->assertSame(16000, (int) $version->fresh()->price);
    }

    public function test_draft_offer_version_can_be_published_to_active(): void
    {
        $version = $this->makeDraftVersion();

        $version->update(['status' => OfferVersion::STATUS_ACTIVE]);

        $this->assertSame(OfferVersion::STATUS_ACTIVE, $version->fresh()->status);
    }

    public function test_active_offer_version_cannot_be_modified(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $version = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();

        try {
            $version->update(['price' => 99999]);
            $modified = true;
        } catch (ImmutableCommercialRecordException) {
            $modified = false;
        }

        $this->assertFalse($modified);
        $this->assertSame(15000, (int) $version->fresh()->price);
    }

    public function test_active_to_retired_only_via_publication_service(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $version = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'SVC-RET-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        try {
            $version->update(['status' => OfferVersion::STATUS_RETIRED]);
            $modified = true;
        } catch (ImmutableCommercialRecordException) {
            $modified = false;
        }

        $this->assertFalse($modified);

        $retired = app(OfferVersionPublicationService::class)->retire($version);

        $this->assertSame(OfferVersion::STATUS_RETIRED, $retired->status);
    }

    public function test_retired_offer_version_cannot_be_modified(): void
    {
        $version = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_RETIRED,
            'code' => 'RET-IMMUT-'.uniqid(),
        ]);

        try {
            $version->update(['description' => 'Changed']);
            $modified = true;
        } catch (ImmutableCommercialRecordException) {
            $modified = false;
        }

        $this->assertFalse($modified);
        $this->assertNotSame('Changed', $version->fresh()->description);
    }

    public function test_subscription_offer_snapshot_cannot_be_modified_after_creation(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $offerVersion = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();
        $subscription = $this->makeSubscription(['offer_version_id' => $offerVersion->id]);

        $snapshot = SubscriptionOfferSnapshot::createFromOfferVersion($subscription, $offerVersion);

        try {
            $snapshot->update(['offer_name' => 'Tampered']);
            $modified = true;
        } catch (ImmutableCommercialRecordException) {
            $modified = false;
        }

        $this->assertFalse($modified);
        $this->assertSame('MKD-Pro Gestion — Forfait base', $snapshot->fresh()->offer_name);
    }

    public function test_subscription_offer_snapshot_cannot_be_deleted(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $offerVersion = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();
        $subscription = $this->makeSubscription(['offer_version_id' => $offerVersion->id]);

        $snapshot = SubscriptionOfferSnapshot::createFromOfferVersion($subscription, $offerVersion);

        try {
            $snapshot->delete();
            $deleted = true;
        } catch (ImmutableCommercialRecordException) {
            $deleted = false;
        }

        $this->assertFalse($deleted);
        $this->assertDatabaseHas('subscription_offer_snapshots', ['id' => $snapshot->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDraftVersion(array $overrides = []): OfferVersion
    {
        $product = Product::query()->create([
            'code' => 'IMM-'.uniqid(),
            'name' => 'Imm Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'IMM-OFF-'.uniqid(),
            'name' => 'Imm Offer',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        $content = CommercialCatalogSeeder::gestBase202601CommercialContent();

        return OfferVersion::query()->create(array_merge([
            'offer_id' => $offer->id,
            'version' => 'draft-'.uniqid(),
            'code' => 'IMM-V-'.uniqid(),
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'status' => OfferVersion::STATUS_DRAFT,
            'description' => $content['description'],
            'inclusions' => $content['inclusions'],
            'limitations' => $content['limitations'],
            'exclusions' => $content['exclusions'],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Client',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation',
            'subdomain' => 'sub-'.uniqid(),
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
