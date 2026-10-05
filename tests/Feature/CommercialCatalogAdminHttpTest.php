<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionOfferSnapshot;
use App\Models\User;
use App\Services\Commercial\OfferVersionPublicationService;
use Database\Seeders\CommercialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class CommercialCatalogAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_cannot_access_commercial_products_index(): void
    {
        $this->get(route('commercial.products.index'))->assertRedirect(route('login'));
    }

    public function test_product_crud_for_authenticated_user(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)->get(route('commercial.products.index'))->assertOk();
        $this->actingAs($user)->get(route('commercial.products.create'))->assertOk();

        $response = $this->actingAs($user)->post(route('commercial.products.store'), [
            'code' => 'PROD-HTTP',
            'name' => 'Produit HTTP',
            'slug' => 'prod-http',
            'description' => 'Desc',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $response->assertRedirect();
        $product = Product::query()->where('code', 'PROD-HTTP')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.product.created']);

        $this->actingAs($user)->get(route('commercial.products.show', $product))->assertOk();
        $this->actingAs($user)->get(route('commercial.products.edit', $product))->assertOk();

        $this->actingAs($user)->put(route('commercial.products.update', $product), [
            'code' => 'PROD-HTTP',
            'name' => 'Produit HTTP modifié',
            'slug' => 'prod-http',
            'description' => 'Desc',
            'status' => Product::STATUS_ACTIVE,
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.product.updated']);
    }

    public function test_product_code_must_be_unique(): void
    {
        $user = $this->controlCenterAdminUser();
        Product::query()->create([
            'code' => 'DUPE-PROD',
            'name' => 'One',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->post(route('commercial.products.store'), [
            'code' => 'DUPE-PROD',
            'name' => 'Two',
            'status' => Product::STATUS_ACTIVE,
        ])->assertSessionHasErrors('code');
    }

    public function test_offer_crud_for_authenticated_user(): void
    {
        $user = $this->controlCenterAdminUser();
        $product = Product::query()->create([
            'code' => 'P-OFF',
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->get(route('commercial.offers.index'))->assertOk();
        $this->actingAs($user)->get(route('commercial.offers.create'))->assertOk();

        $this->actingAs($user)->post(route('commercial.offers.store'), [
            'product_id' => $product->id,
            'code' => 'OFF-HTTP',
            'name' => 'Offre HTTP',
            'status' => Offer::STATUS_ACTIVE,
        ])->assertRedirect();

        $offer = Offer::query()->where('code', 'OFF-HTTP')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.offer.created']);

        $this->actingAs($user)->get(route('commercial.offers.show', $offer))->assertOk();
        $this->actingAs($user)->put(route('commercial.offers.update', $offer), [
            'product_id' => $product->id,
            'code' => 'OFF-HTTP',
            'name' => 'Offre modifiée',
            'status' => Offer::STATUS_ACTIVE,
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.offer.updated']);
    }

    public function test_offer_requires_product(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)->post(route('commercial.offers.store'), [
            'code' => 'NO-PROD',
            'name' => 'Offre',
            'status' => Offer::STATUS_ACTIVE,
        ])->assertSessionHasErrors('product_id');
    }

    public function test_offer_version_store_always_creates_draft(): void
    {
        $user = $this->controlCenterAdminUser();
        $offer = $this->makeOffer('OFF-DRAFT-HTTP');

        $payload = $this->validDraftPayload($offer);

        $this->actingAs($user)->post(route('commercial.offer-versions.store'), $payload)->assertRedirect();

        $version = OfferVersion::query()->where('code', $payload['code'])->firstOrFail();
        $this->assertSame(OfferVersion::STATUS_DRAFT, $version->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.offer_version.created']);
    }

    public function test_offer_version_store_rejects_status_active_in_request(): void
    {
        $user = $this->controlCenterAdminUser();
        $offer = $this->makeOffer('OFF-STAT');

        $payload = $this->validDraftPayload($offer);
        $payload['status'] = OfferVersion::STATUS_ACTIVE;

        $this->actingAs($user)->post(route('commercial.offer-versions.store'), $payload)
            ->assertSessionHasErrors('status');

        $this->assertSame(0, OfferVersion::query()->where('code', $payload['code'])->count());
    }

    public function test_draft_can_be_updated_via_http(): void
    {
        $user = $this->controlCenterAdminUser();
        $version = $this->makeDraftForHttp('DRAFT-UPD');

        $this->actingAs($user)->get(route('commercial.offer-versions.edit', $version))->assertOk();

        $payload = $this->validDraftPayload($version->offer, $version);
        $payload['price'] = 16000;

        $this->actingAs($user)->put(route('commercial.offer-versions.update', $version), $payload)->assertRedirect();

        $this->assertSame(16000, (int) $version->fresh()->price);
    }

    public function test_edit_and_update_active_version_are_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $active = $this->makeDraftForHttp('ACT-EDIT', ['status' => OfferVersion::STATUS_ACTIVE]);

        $this->actingAs($user)->get(route('commercial.offer-versions.edit', $active))
            ->assertRedirect(route('commercial.offer-versions.show', $active));

        $payload = $this->validDraftPayload($active->offer, $active);
        $payload['price'] = 99999;

        $this->actingAs($user)->put(route('commercial.offer-versions.update', $active), $payload)
            ->assertSessionHasErrors('status');

        $this->assertSame(15000, (int) $active->fresh()->price);
    }

    public function test_retired_version_show_ok_edit_and_update_refused(): void
    {
        $user = $this->controlCenterAdminUser();
        $retired = $this->makeDraftForHttp('RET-EDIT', ['status' => OfferVersion::STATUS_RETIRED]);

        $this->actingAs($user)->get(route('commercial.offer-versions.show', $retired))->assertOk();
        $this->actingAs($user)->get(route('commercial.offer-versions.edit', $retired))
            ->assertRedirect(route('commercial.offer-versions.show', $retired));

        $payload = $this->validDraftPayload($retired->offer, $retired);
        $this->actingAs($user)->put(route('commercial.offer-versions.update', $retired), $payload)
            ->assertSessionHasErrors('status');
    }

    public function test_http_update_cannot_set_status_active_on_draft(): void
    {
        $user = $this->controlCenterAdminUser();
        $draft = $this->makeDraftForHttp('NO-HTTP-ACT');

        $payload = $this->validDraftPayload($draft->offer, $draft);
        $payload['status'] = OfferVersion::STATUS_ACTIVE;

        $this->actingAs($user)->put(route('commercial.offer-versions.update', $draft), $payload)
            ->assertSessionHasErrors('status');

        $this->assertSame(OfferVersion::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_publish_and_retire_via_http_use_workflow(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $draft = $this->makeDraftForHttp('PUB-HTTP', ['effective_from' => '2026-01-01 00:00:00']);

        $this->actingAs($user)->post(route('commercial.offer-versions.publish', $draft))->assertRedirect();
        $this->assertSame(OfferVersion::STATUS_ACTIVE, $draft->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'catalog.offer_version.published')->count());

        $this->actingAs($user)->post(route('commercial.offer-versions.retire', $draft))->assertRedirect();
        $this->assertSame(OfferVersion::STATUS_RETIRED, $draft->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'catalog.offer_version.retired')->count());
    }

    public function test_publish_http_delegates_to_publication_service(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $draft = $this->makeDraftForHttp('MOCK-PUB');

        $mock = Mockery::mock(OfferVersionPublicationService::class);
        $mock->shouldReceive('publish')->once()->andReturn($draft);
        $this->app->instance(OfferVersionPublicationService::class, $mock);

        $this->actingAs($user)->post(route('commercial.offer-versions.publish', $draft))->assertRedirect();
    }

    public function test_retire_http_delegates_to_publication_service(): void
    {
        $user = $this->controlCenterAdminUser();
        $active = $this->makeDraftForHttp('MOCK-RET', ['status' => OfferVersion::STATUS_ACTIVE]);

        $mock = Mockery::mock(OfferVersionPublicationService::class);
        $mock->shouldReceive('retire')->once()->andReturn($active);
        $this->app->instance(OfferVersionPublicationService::class, $mock);

        $this->actingAs($user)->post(route('commercial.offer-versions.retire', $active))->assertRedirect();
    }

    public function test_publish_fails_when_another_active_exists(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $offer = $this->makeOffer('OFF-2ACT');
        $this->makeDraftForHttp('V1', ['offer_id' => $offer->id, 'code' => 'V1-'.uniqid(), 'version' => '2026-01', 'status' => OfferVersion::STATUS_ACTIVE]);
        $draft = $this->makeDraftForHttp('V2', ['offer_id' => $offer->id, 'code' => 'V2-'.uniqid(), 'version' => '2026-02']);

        $this->actingAs($user)->post(route('commercial.offer-versions.publish', $draft))
            ->assertRedirect(route('commercial.offer-versions.show', $draft))
            ->assertSessionHas('error');

        $this->assertSame(OfferVersion::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_draft_with_future_effective_from_allowed_until_publish(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $draft = $this->makeDraftForHttp('FUT-DR', ['effective_from' => '2026-12-01 00:00:00']);

        $this->actingAs($user)->post(route('commercial.offer-versions.publish', $draft))
            ->assertSessionHas('error');

        $this->assertSame(0, AuditLog::query()->where('action', 'catalog.offer_version.published')->where('result', 'success')->count());
    }

    public function test_product_delete_blocked_when_offers_exist(): void
    {
        $product = Product::query()->create([
            'code' => 'P-FK',
            'name' => 'P',
            'status' => Product::STATUS_ACTIVE,
        ]);
        Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'O-FK',
            'name' => 'O',
            'status' => Offer::STATUS_ACTIVE,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $product->delete();
    }

    public function test_offer_version_delete_blocked_when_subscription_references(): void
    {
        $this->seed(CommercialCatalogSeeder::class);
        $version = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();
        $subscription = $this->makeSubscription(['offer_version_id' => $version->id]);

        $this->assertNotNull($subscription->offer_version_id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $version->delete();
    }

    public function test_offer_version_delete_blocked_when_snapshot_references(): void
    {
        $this->seed(CommercialCatalogSeeder::class);
        $version = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();
        $subscription = $this->makeSubscription(['offer_version_id' => $version->id]);
        SubscriptionOfferSnapshot::createFromOfferVersion($subscription, $version);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $version->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOffer(string $code, array $overrides = []): Offer
    {
        $product = Product::query()->create([
            'code' => 'P-'.$code,
            'name' => 'Product',
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
    private function makeDraftForHttp(string $prefix, array $overrides = []): OfferVersion
    {
        $offer = isset($overrides['offer_id'])
            ? Offer::query()->findOrFail($overrides['offer_id'])
            : $this->makeOffer('OFF-'.$prefix);

        $content = CommercialCatalogSeeder::gestBase202601CommercialContent();

        return OfferVersion::query()->create(array_merge([
            'offer_id' => $offer->id,
            'version' => 'v-'.$prefix,
            'code' => $prefix.'-'.uniqid(),
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
     * @return array<string, mixed>
     */
    private function validDraftPayload(Offer $offer, ?OfferVersion $existing = null): array
    {
        $content = CommercialCatalogSeeder::gestBase202601CommercialContent();

        return [
            'offer_id' => $offer->id,
            'code' => $existing?->code ?? ('CODE-'.uniqid()),
            'version' => $existing?->version ?? ('ver-'.uniqid()),
            'description' => $content['description'],
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => null,
            'commercial_conditions' => null,
            'inclusions' => $content['inclusions']['items'],
            'limitations' => $content['limitations']['items'],
            'exclusions' => $content['exclusions']['items'],
        ];
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
