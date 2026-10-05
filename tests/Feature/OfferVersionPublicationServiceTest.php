<?php

namespace Tests\Feature;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use App\Exceptions\Commercial\OfferVersionWorkflowException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\Commercial\OfferVersionPublicationService;
use Database\Seeders\CommercialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfferVersionPublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private OfferVersionPublicationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OfferVersionPublicationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_publish_draft_to_active_succeeds(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion();

        $published = $this->service->publish($draft);

        $this->assertSame(OfferVersion::STATUS_ACTIVE, $published->status);
        $this->assertSame(15000, (int) $published->price);
    }

    public function test_publish_validates_commercial_content(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion(['description' => '']);

        $this->expectException(OfferVersionWorkflowException::class);

        $this->service->publish($draft);
    }

    public function test_publish_active_version_fails(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $active = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();

        $this->expectException(OfferVersionWorkflowException::class);
        $this->expectExceptionMessage('déjà publiée');

        $this->service->publish($active);
    }

    public function test_publish_retired_version_fails(): void
    {
        $retired = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_RETIRED,
            'code' => 'RET-PUB-'.uniqid(),
        ]);

        $this->expectException(OfferVersionWorkflowException::class);
        $this->expectExceptionMessage('retirée');

        $this->service->publish($retired);
    }

    public function test_publish_fails_when_another_version_is_active(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $offer = $this->makeOffer('OFF-TWO-ACT');
        $this->makeDraftVersionForOffer($offer, [
            'code' => 'V1-'.uniqid(),
            'status' => OfferVersion::STATUS_ACTIVE,
            'version' => '2026-01',
        ]);

        $draft = $this->makeDraftVersionForOffer($offer, [
            'code' => 'V2-'.uniqid(),
            'version' => '2026-02',
        ]);

        $this->expectException(OfferVersionWorkflowException::class);
        $this->expectExceptionMessage('autre version active');

        $this->service->publish($draft);
    }

    public function test_publish_creates_audit_log(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion();

        $published = $this->service->publish($draft);

        $log = AuditLog::query()->where('action', 'catalog.offer_version.published')->sole();

        $this->assertSame($published->id, $log->auditable_id);
        $this->assertSame(OfferVersion::STATUS_DRAFT, $log->old_values['status']);
        $this->assertSame(OfferVersion::STATUS_ACTIVE, $log->new_values['status']);
        $this->assertSame('success', $log->result);
    }

    public function test_failed_publish_does_not_create_success_audit(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion(['effective_from' => '2027-01-01 00:00:00']);

        try {
            $this->service->publish($draft);
            $this->fail('Expected OfferVersionWorkflowException.');
        } catch (OfferVersionWorkflowException) {
            // attendu
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'catalog.offer_version.published')->count());
        $this->assertSame(OfferVersion::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_publish_does_not_change_price_or_content(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion(['price' => 16000]);
        $descriptionBefore = $draft->description;

        $published = $this->service->publish($draft);

        $this->assertSame(16000, (int) $published->price);
        $this->assertSame($descriptionBefore, $published->description);
    }

    public function test_publish_does_not_touch_subscription_or_payment(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $subscription = $this->makeSubscription(['amount' => 18000]);
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 18000,
            'monthly_unit_amount' => 18000,
            'currency' => 'XOF',
            'credit_months_purchased' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $draft = $this->makeDraftVersion();
        $this->service->publish($draft);

        $this->assertSame(18000, (int) $subscription->fresh()->amount);
        $this->assertSame(18000, (int) $payment->fresh()->amount);
        $this->assertSame(18000, (int) $payment->fresh()->monthly_unit_amount);
    }

    public function test_effective_until_before_effective_from_is_rejected_on_publish(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion([
            'effective_from' => '2026-06-01 00:00:00',
            'effective_until' => '2026-05-01 00:00:00',
        ]);

        $this->expectException(OfferVersionWorkflowException::class);

        $this->service->publish($draft);
    }

    public function test_effective_from_in_future_is_rejected_on_publish(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion(['effective_from' => '2026-07-01 00:00:00']);

        $this->expectException(OfferVersionWorkflowException::class);
        $this->expectExceptionMessage('futur');

        $this->service->publish($draft);
    }

    public function test_draft_may_have_future_effective_from_before_publish(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion(['effective_from' => '2026-08-01 00:00:00']);

        $this->assertSame(OfferVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame('2026-08-01 00:00:00', $draft->effective_from->format('Y-m-d H:i:s'));
    }

    public function test_effective_until_in_past_is_rejected_on_publish(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $draft = $this->makeDraftVersion([
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => '2026-05-01 00:00:00',
        ]);

        $this->expectException(OfferVersionWorkflowException::class);

        $this->service->publish($draft);
    }

    public function test_retire_active_to_retired_succeeds(): void
    {
        Carbon::setTestNow('2026-06-15 10:30:00');

        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'ACT-RET-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => null,
        ]);

        $retired = $this->service->retire($active);

        $this->assertSame(OfferVersion::STATUS_RETIRED, $retired->status);
        $this->assertSame('2026-06-15 10:30:00', $retired->effective_until->format('Y-m-d H:i:s'));
    }

    public function test_retire_sets_effective_until_only_when_null(): void
    {
        Carbon::setTestNow('2026-06-15 10:30:00');

        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'ACT-UNTIL-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => '2026-12-31 23:59:59',
        ]);

        $retired = $this->service->retire($active);

        $this->assertSame('2026-12-31 23:59:59', $retired->effective_until->format('Y-m-d H:i:s'));
    }

    public function test_retire_draft_fails(): void
    {
        $draft = $this->makeDraftVersion();

        $this->expectException(OfferVersionWorkflowException::class);

        $this->service->retire($draft);
    }

    public function test_retire_already_retired_fails(): void
    {
        $retired = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_RETIRED,
            'code' => 'RET-RET-'.uniqid(),
        ]);

        $this->expectException(OfferVersionWorkflowException::class);

        $this->service->retire($retired);
    }

    public function test_retire_does_not_change_price_code_or_content(): void
    {
        Carbon::setTestNow('2026-06-15 10:30:00');

        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'KEEP-FIELDS-'.uniqid(),
            'price' => 17000,
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        $code = $active->code;
        $description = $active->description;

        $retired = $this->service->retire($active);

        $this->assertSame($code, $retired->code);
        $this->assertSame(17000, (int) $retired->price);
        $this->assertSame($description, $retired->description);
    }

    public function test_retire_creates_audit_log(): void
    {
        Carbon::setTestNow('2026-06-15 10:30:00');

        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'AUD-RET-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        $this->service->retire($active);

        $log = AuditLog::query()->where('action', 'catalog.offer_version.retired')->sole();

        $this->assertSame(OfferVersion::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(OfferVersion::STATUS_RETIRED, $log->new_values['status']);
    }

    public function test_retire_does_not_touch_subscription_or_payment(): void
    {
        Carbon::setTestNow('2026-06-15 10:30:00');

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

        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'ISO-RET-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        $this->service->retire($active);

        $this->assertSame(15000, (int) $subscription->fresh()->amount);
        $this->assertSame(15000, (int) $payment->fresh()->amount);
    }

    public function test_active_to_retired_via_generic_update_is_refused(): void
    {
        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'NO-DIRECT-RET-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        try {
            $active->update(['status' => OfferVersion::STATUS_RETIRED]);
            $modified = true;
        } catch (ImmutableCommercialRecordException) {
            $modified = false;
        }

        $this->assertFalse($modified);
        $this->assertSame(OfferVersion::STATUS_ACTIVE, $active->fresh()->status);
    }

    public function test_active_price_code_and_dates_cannot_be_modified_directly(): void
    {
        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'NO-EDIT-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        foreach ([
            ['price' => 99999],
            ['code' => 'HACK'],
            ['effective_from' => '2020-01-01 00:00:00'],
        ] as $changes) {
            try {
                $active->update($changes);
                $modified = true;
            } catch (ImmutableCommercialRecordException) {
                $modified = false;
            }

            $this->assertFalse($modified, 'Modification inattendue : '.json_encode($changes));
            $active->refresh();
        }
    }

    public function test_retired_version_remains_readable_after_retirement(): void
    {
        Carbon::setTestNow('2026-06-15 10:30:00');

        $active = $this->makeDraftVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'READ-RET-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        $this->service->retire($active);

        $found = OfferVersion::query()->where('code', $active->code)->firstOrFail();

        $this->assertSame(OfferVersion::STATUS_RETIRED, $found->status);
    }

    public function test_sequential_retire_then_publish_second_version(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $offer = $this->makeOffer('OFF-SEQ');
        $v1 = $this->makeDraftVersionForOffer($offer, [
            'code' => 'SEQ-V1-'.uniqid(),
            'version' => '2026-01',
            'status' => OfferVersion::STATUS_ACTIVE,
            'effective_from' => '2026-01-01 00:00:00',
        ]);
        $v2 = $this->makeDraftVersionForOffer($offer, [
            'code' => 'SEQ-V2-'.uniqid(),
            'version' => '2026-02',
            'effective_from' => '2026-06-01 00:00:00',
        ]);

        $this->service->retire($v1);
        $published = $this->service->publish($v2);

        $this->assertSame(OfferVersion::STATUS_ACTIVE, $published->status);
        $this->assertSame(1, OfferVersion::query()->where('offer_id', $offer->id)->where('status', OfferVersion::STATUS_ACTIVE)->count());
    }

    private function makeOffer(string $code): Offer
    {
        $product = Product::query()->create([
            'code' => 'P-'.$code,
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);

        return Offer::query()->create([
            'product_id' => $product->id,
            'code' => $code,
            'name' => 'Offer '.$code,
            'status' => Offer::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDraftVersion(array $overrides = []): OfferVersion
    {
        $offer = $this->makeOffer('OFF-'.uniqid());

        return $this->makeDraftVersionForOffer($offer, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDraftVersionForOffer(Offer $offer, array $overrides = []): OfferVersion
    {
        $content = CommercialCatalogSeeder::gestBase202601CommercialContent();

        return OfferVersion::query()->create(array_merge([
            'offer_id' => $offer->id,
            'version' => $overrides['version'] ?? ('draft-'.uniqid()),
            'code' => $overrides['code'] ?? ('CODE-'.uniqid()),
            'price' => 15000,
            'currency' => 'XOF',
            'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => null,
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
