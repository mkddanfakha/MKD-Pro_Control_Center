<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Product;
use Database\Seeders\CommercialCatalogSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_be_created(): void
    {
        $product = Product::query()->create([
            'code' => 'TEST-PROD',
            'name' => 'Test Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('products', ['code' => 'TEST-PROD']);
        $this->assertSame('Test Product', $product->name);
    }

    public function test_product_code_is_unique(): void
    {
        Product::query()->create([
            'code' => 'DUPE',
            'name' => 'One',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Product::query()->create([
            'code' => 'DUPE',
            'name' => 'Two',
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    public function test_offer_belongs_to_product(): void
    {
        $product = Product::query()->create([
            'code' => 'P1',
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'OFF-1',
            'name' => 'Offer',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        $this->assertTrue($offer->product->is($product));
        $this->assertSame(1, $product->offers()->count());
    }

    public function test_offer_code_is_unique(): void
    {
        $product = Product::query()->create([
            'code' => 'P2',
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'SAME-OFFER',
            'name' => 'One',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'SAME-OFFER',
            'name' => 'Two',
            'status' => Offer::STATUS_ACTIVE,
        ]);
    }

    public function test_offer_version_belongs_to_offer(): void
    {
        $offer = $this->makeOffer('OFF-V');

        $version = $this->makeOfferVersion($offer, 'draft-v1', [
            'status' => OfferVersion::STATUS_DRAFT,
        ]);

        $this->assertTrue($version->offer->is($offer));
        $this->assertSame(1, $offer->versions()->count());
    }

    public function test_offer_version_code_is_unique(): void
    {
        $offer = $this->makeOffer('OFF-CODE');

        $this->makeOfferVersion($offer, 'ver-a', ['code' => 'UNIQUE-CODE-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeOfferVersion($offer, 'ver-b', ['code' => 'UNIQUE-CODE-1']);
    }

    public function test_offer_id_and_version_combination_is_unique(): void
    {
        $offer = $this->makeOffer('OFF-PAIR');

        $this->makeOfferVersion($offer, '2026-02', ['code' => 'CODE-A']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeOfferVersion($offer, '2026-02', ['code' => 'CODE-B']);
    }

    public function test_offer_version_can_be_draft(): void
    {
        $offer = $this->makeOffer('OFF-DRAFT');
        $version = $this->makeOfferVersion($offer, 'draft-1', [
            'status' => OfferVersion::STATUS_DRAFT,
        ]);

        $this->assertSame(OfferVersion::STATUS_DRAFT, $version->status);
    }

    public function test_seeded_active_version_has_expected_commercial_data(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $version = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();

        $this->assertSame(OfferVersion::STATUS_ACTIVE, $version->status);
        $this->assertSame(15000, (int) $version->price);
        $this->assertSame('XOF', $version->currency);
        $this->assertSame('monthly', $version->billing_cycle);
        $this->assertSame('2026-01', $version->version);

        $inclusions = $version->inclusions['items'] ?? [];
        $this->assertContains('Ventes', $inclusions);
        $this->assertContains('Factures PDF', $inclusions);
        $this->assertContains('Authentification à deux facteurs (2FA)', $inclusions);

        $limitations = $version->limitations['items'] ?? [];
        $this->assertContains('Wave et Orange Money comme modes de paiement manuels uniquement', $limitations);

        $exclusions = $version->exclusions['items'] ?? [];
        $this->assertContains('Caisse (POS)', $exclusions);
        $this->assertContains('Import catalogue Excel', $exclusions);
    }

    public function test_retired_offer_version_remains_readable(): void
    {
        $offer = $this->makeOffer('OFF-RET');

        $version = $this->makeOfferVersion($offer, '2025-12', [
            'code' => 'RETIRED-V',
            'status' => OfferVersion::STATUS_RETIRED,
        ]);

        $found = OfferVersion::query()->findOrFail($version->id);

        $this->assertSame(OfferVersion::STATUS_RETIRED, $found->status);
        $this->assertSame('RETIRED-V', $found->code);
    }

    public function test_commercial_catalog_seeder_is_idempotent(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $products = Product::query()->count();
        $offers = Offer::query()->count();
        $versions = OfferVersion::query()->count();

        $this->seed(CommercialCatalogSeeder::class);

        $this->assertSame($products, Product::query()->count());
        $this->assertSame($offers, Offer::query()->count());
        $this->assertSame($versions, OfferVersion::query()->count());

        $this->assertSame(1, Product::query()->where('code', 'GEST')->count());
        $this->assertSame(1, Offer::query()->where('code', 'MKD-GEST-BASE')->count());
        $this->assertSame(1, OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOffer(string $code, array $overrides = []): Offer
    {
        $product = Product::query()->create([
            'code' => 'P-'.$code,
            'name' => 'Product '.$code,
            'status' => Product::STATUS_ACTIVE,
        ]);

        return Offer::query()->create(array_merge([
            'product_id' => $product->id,
            'code' => $code,
            'name' => 'Offer '.$code,
            'status' => Offer::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOfferVersion(Offer $offer, string $versionLabel, array $overrides = []): OfferVersion
    {
        $content = CommercialCatalogSeeder::gestBase202601CommercialContent();

        return OfferVersion::query()->create(array_merge([
            'offer_id' => $offer->id,
            'version' => $versionLabel,
            'code' => $overrides['code'] ?? ('CODE-'.$versionLabel.'-'.uniqid()),
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
}
