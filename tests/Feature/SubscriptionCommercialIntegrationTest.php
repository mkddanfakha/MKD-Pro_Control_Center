<?php

namespace Tests\Feature;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use App\Exceptions\Subscription\SubscriptionPeriodException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionOfferSnapshot;
use App\Models\User;
use App\Services\Commercial\CommercialSubscriptionService;
use App\Services\Commercial\OfferVersionPublicationService;
use Database\Seeders\CommercialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSubscriptionStorePayload;
use Tests\TestCase;

class SubscriptionCommercialIntegrationTest extends TestCase
{
    use BuildsSubscriptionStorePayload;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_selectable_offer_versions_include_only_valid_active_versions(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $this->seed(CommercialCatalogSeeder::class);

        $service = app(CommercialSubscriptionService::class);
        $ids = $service->selectableActiveOfferVersions()->pluck('id')->all();

        $catalogActive = OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();
        $this->assertContains($catalogActive->id, $ids);

        $draft = $this->makeVersion(['status' => OfferVersion::STATUS_DRAFT, 'code' => 'DRAFT-SEL-'.uniqid()]);
        $retired = $this->makeVersion(['status' => OfferVersion::STATUS_RETIRED, 'code' => 'RET-SEL-'.uniqid()]);
        $future = $this->makeVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'FUT-SEL-'.uniqid(),
            'effective_from' => '2026-12-01 00:00:00',
        ]);
        $expired = $this->makeVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'EXP-SEL-'.uniqid(),
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => '2026-05-01 00:00:00',
        ]);

        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($retired->id, $ids);
        $this->assertNotContains($future->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    public function test_store_rejects_draft_retired_future_and_expired_offer_versions(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $draft = $this->makeVersion(['status' => OfferVersion::STATUS_DRAFT]);
        $this->actingAs($user)->post(route('subscriptions.store'), $this->basePayload($installation->id, $draft))->assertSessionHasErrors('offer_version_id');

        $retired = $this->makeVersion(['status' => OfferVersion::STATUS_RETIRED]);
        $this->actingAs($user)->post(route('subscriptions.store'), $this->basePayload($installation->id, $retired))->assertSessionHasErrors('offer_version_id');

        $future = $this->makeVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'effective_from' => '2026-12-01 00:00:00',
        ]);
        $this->actingAs($user)->post(route('subscriptions.store'), $this->basePayload($installation->id, $future))->assertSessionHasErrors('offer_version_id');

        $expired = $this->makeVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'effective_from' => '2026-01-01 00:00:00',
            'effective_until' => '2026-05-01 00:00:00',
        ]);
        $this->actingAs($user)->post(route('subscriptions.store'), $this->basePayload($installation->id, $expired))->assertSessionHasErrors('offer_version_id');
    }

    public function test_store_creates_subscription_snapshot_and_audit_with_catalogue_price(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id))
            ->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $this->assertSame($offerVersion->id, $subscription->offer_version_id);
        $this->assertSame(15000, (int) $subscription->amount);
        $this->assertSame(0, Payment::query()->count());

        $snapshot = SubscriptionOfferSnapshot::query()->where('subscription_id', $subscription->id)->sole();
        $this->assertSame($offerVersion->id, $snapshot->offer_version_id);
        $this->assertSame(15000, (int) $snapshot->catalogue_price);
        $this->assertSame(15000, (int) $snapshot->effective_price_at_subscription);
        $this->assertSame('XOF', $snapshot->currency);
        $this->assertSame('monthly', $snapshot->billing_cycle);
        $this->assertNotEmpty($snapshot->inclusions['items'] ?? []);
    }

    public function test_store_supports_negotiated_rate_in_snapshot(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 18000,
            'negotiated_rate_reason' => 'Remise commerciale négociée',
        ]))->assertRedirect();

        $this->assertSame(15000, (int) $offerVersion->fresh()->price);

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $this->assertSame(18000, (int) $subscription->amount);

        $snapshot = SubscriptionOfferSnapshot::query()->where('subscription_id', $subscription->id)->sole();
        $this->assertSame(15000, (int) $snapshot->catalogue_price);
        $this->assertSame(18000, (int) $snapshot->effective_price_at_subscription);
        $this->assertSame('Remise commerciale négociée', $snapshot->negotiated_rate_reason);
    }

    public function test_offer_version_change_does_not_alter_existing_snapshot(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 18000,
        ]));

        $snapshot = SubscriptionOfferSnapshot::query()->sole();
        $originalInclusions = $snapshot->inclusions;

        DB::table('offer_versions')->where('id', $offerVersion->id)->update(['price' => 16000]);

        $snapshot->refresh();
        $this->assertSame(15000, (int) $snapshot->catalogue_price);
        $this->assertSame(18000, (int) $snapshot->effective_price_at_subscription);
        $this->assertSame($originalInclusions, $snapshot->inclusions);
    }

    public function test_subscription_update_changes_amount_but_not_snapshot(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 15000,
        ]));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $snapshot = SubscriptionOfferSnapshot::query()->sole();

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 20000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->assertSame(20000, (int) $subscription->fresh()->amount);
        $snapshot->refresh();
        $this->assertSame(15000, (int) $snapshot->effective_price_at_subscription);
    }

    public function test_retiring_offer_version_does_not_change_subscription_reference_or_snapshot(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $snapshotBefore = SubscriptionOfferSnapshot::query()->sole()->toArray();

        app(OfferVersionPublicationService::class)->retire($offerVersion);

        $subscription->refresh();
        $this->assertSame($offerVersion->id, $subscription->offer_version_id);
        $this->assertSame(OfferVersion::STATUS_RETIRED, $offerVersion->fresh()->status);

        $snapshotAfter = SubscriptionOfferSnapshot::query()->sole()->toArray();
        unset($snapshotBefore['created_at'], $snapshotAfter['created_at']);
        $this->assertSame($snapshotBefore, $snapshotAfter);
    }

    public function test_store_rolls_back_subscription_and_snapshot_on_failure(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->mock(\App\Services\SubscriptionService::class, function ($mock): void {
            $mock->shouldReceive('createInitialPeriod')->andThrow(new SubscriptionPeriodException('fail'));
        });

        try {
            $this->withoutExceptionHandling();
            $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));
            $this->fail('Expected exception');
        } catch (SubscriptionPeriodException) {
        }

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, SubscriptionOfferSnapshot::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.created')->count());
    }

    public function test_snapshot_cannot_be_updated_after_creation(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $snapshot = SubscriptionOfferSnapshot::query()->sole();

        try {
            $snapshot->update(['catalogue_price' => 1]);
            $changed = true;
        } catch (ImmutableCommercialRecordException) {
            $changed = false;
        }

        $this->assertFalse($changed);
    }

    public function test_new_subscription_uses_new_active_offer_version_after_previous_is_retired(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();
        $offerVersionA = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installationA->id, [
            'amount' => 18000,
            'negotiated_rate_reason' => 'Tarif S1',
        ]));

        $subscriptionS1 = Subscription::query()->where('installation_id', $installationA->id)->firstOrFail();
        $snapshotS1 = SubscriptionOfferSnapshot::query()->where('subscription_id', $subscriptionS1->id)->sole();

        app(OfferVersionPublicationService::class)->retire($offerVersionA);

        $offerVersionB = $this->makeVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'MKD-GEST-BASE-2026-02',
            'price' => 20000,
        ]);

        $this->actingAs($user)->post(route('subscriptions.store'), array_merge(
            $this->basePayload($installationB->id, $offerVersionB),
            ['amount' => 20000],
        ))->assertRedirect();

        $subscriptionS2 = Subscription::query()->where('installation_id', $installationB->id)->firstOrFail();
        $snapshotS2 = SubscriptionOfferSnapshot::query()->where('subscription_id', $subscriptionS2->id)->sole();

        $subscriptionS1->refresh();
        $snapshotS1->refresh();

        $this->assertSame($offerVersionA->id, $subscriptionS1->offer_version_id);
        $this->assertSame('MKD-GEST-BASE-2026-01', $snapshotS1->offer_version_code);
        $this->assertSame(15000, (int) $snapshotS1->catalogue_price);
        $this->assertSame(18000, (int) $snapshotS1->effective_price_at_subscription);
        $this->assertSame('Tarif S1', $snapshotS1->negotiated_rate_reason);

        $this->assertSame($offerVersionB->id, $subscriptionS2->offer_version_id);
        $this->assertSame(20000, (int) $snapshotS2->catalogue_price);
        $this->assertSame(20000, (int) $snapshotS2->effective_price_at_subscription);
        $this->assertNotSame($snapshotS1->offer_version_code, $snapshotS2->offer_version_code);
    }

    public function test_two_subscriptions_on_different_offer_versions_keep_independent_histories(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();
        $offerVersionA = $this->seedActiveCatalogOfferVersion();
        $offerVersionB = $this->makeVersion([
            'status' => OfferVersion::STATUS_ACTIVE,
            'code' => 'PARALLEL-B-'.uniqid(),
            'price' => 22000,
        ]);

        $this->actingAs($user)->post(route('subscriptions.store'), $this->basePayload($installationA->id, $offerVersionA));
        $this->actingAs($user)->post(route('subscriptions.store'), $this->basePayload($installationB->id, $offerVersionB));

        $snapshotA = SubscriptionOfferSnapshot::query()->whereHas('subscription', fn ($q) => $q->where('installation_id', $installationA->id))->sole();
        $snapshotB = SubscriptionOfferSnapshot::query()->whereHas('subscription', fn ($q) => $q->where('installation_id', $installationB->id))->sole();

        $this->assertSame(15000, (int) $snapshotA->catalogue_price);
        $this->assertSame(22000, (int) $snapshotB->catalogue_price);
        $this->assertSame($offerVersionA->code, $snapshotA->offer_version_code);
        $this->assertSame($offerVersionB->code, $snapshotB->offer_version_code);
    }

    public function test_subscription_update_rejects_offer_version_id_change(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersionA = $this->seedActiveCatalogOfferVersion();
        $offerVersionB = $this->makeVersion(['status' => OfferVersion::STATUS_ACTIVE]);

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $originalOfferVersionId = $subscription->offer_version_id;

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'offer_version_id' => $offerVersionB->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('offer_version_id');

        $this->assertSame($originalOfferVersionId, $subscription->fresh()->offer_version_id);
        $this->assertSame($offerVersionA->id, $originalOfferVersionId);
    }

    public function test_legacy_subscription_without_offer_version_stays_unchanged_on_show(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'offer_version_id' => null,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-06-01 00:00:00',
            'current_period_start' => '2026-06-01 00:00:00',
            'current_period_end' => '2026-06-30 23:59:59',
        ]);

        $response = $this->actingAs($user)->get(route('subscriptions.show', $subscription));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Subscriptions/Show')
            ->where('commercial.history_status', 'legacy_unavailable')
            ->where('commercial.legacy_unspecified', true)
        );

        $subscription->refresh();
        $this->assertNull($subscription->offer_version_id);
        $this->assertSame(0, SubscriptionOfferSnapshot::query()->count());
    }

    public function test_show_does_not_create_snapshot_for_legacy_subscription(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-06-01 00:00:00',
            'current_period_start' => '2026-06-01 00:00:00',
            'current_period_end' => '2026-06-30 23:59:59',
        ]);

        $this->actingAs($user)->get(route('subscriptions.show', $subscription));

        $this->assertSame(0, SubscriptionOfferSnapshot::query()->count());
    }

    public function test_show_does_not_create_snapshot_when_offer_version_id_present_but_snapshot_missing(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'offer_version_id' => $offerVersion->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-06-01 00:00:00',
            'current_period_start' => '2026-06-01 00:00:00',
            'current_period_end' => '2026-06-30 23:59:59',
        ]);

        $response = $this->actingAs($user)->get(route('subscriptions.show', $subscription));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('commercial.history_status', 'missing_snapshot'));
        $this->assertSame(0, SubscriptionOfferSnapshot::query()->count());
    }

    public function test_show_performs_no_database_writes_for_subscription_with_commercial_history(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $snapshotCountBefore = SubscriptionOfferSnapshot::query()->count();
        $updatedAtBefore = $subscription->updated_at?->format('Y-m-d H:i:s');

        $this->actingAs($user)->get(route('subscriptions.show', $subscription));

        $this->assertSame($snapshotCountBefore, SubscriptionOfferSnapshot::query()->count());
        $subscription->refresh();
        $this->assertSame($updatedAtBefore, $subscription->updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_deleting_offer_version_used_by_subscription_is_blocked_by_foreign_key(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $this->expectException(\Illuminate\Database\QueryException::class);

        $offerVersion->delete();
    }

    public function test_subscription_creation_audit_remains_valid_after_commercial_hardening(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $log = AuditLog::query()->where('action', 'subscription.created')->sole();

        $this->assertSame($subscription->id, $log->auditable_id);
        $this->assertSame($subscription->offer_version_id, $log->new_values['offer_version_id']);
        $this->assertArrayHasKey('offer_version_code', $log->new_values);
        $this->assertArrayHasKey('catalogue_price', $log->new_values);
        $this->assertArrayHasKey('effective_price_at_subscription', $log->new_values);
    }

    public function test_normal_subscription_update_workflow_regression(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'notes' => 'Note initiale',
        ]));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 16000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
            'notes' => 'Note modifiée',
        ])->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();
        $this->assertSame(16000, (int) $subscription->amount);
        $this->assertSame('Note modifiée', $subscription->notes);

        $updateLog = AuditLog::query()->where('action', 'subscription.updated')->sole();
        $this->assertSame(15000, $updateLog->old_values['amount']);
        $this->assertSame(16000, $updateLog->new_values['amount']);
        $this->assertSame($subscription->offer_version_id, $updateLog->new_values['offer_version_id']);
    }

    public function test_model_cannot_change_offer_version_id_after_creation(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $otherVersion = $this->makeVersion(['status' => OfferVersion::STATUS_ACTIVE]);

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        try {
            $subscription->update(['offer_version_id' => $otherVersion->id]);
            $changed = true;
        } catch (ImmutableCommercialRecordException) {
            $changed = false;
        }

        $this->assertFalse($changed);
    }

    public function test_amount_update_preserves_snapshot_catalogue_price_and_negotiated_reason(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 18000,
            'negotiated_rate_reason' => 'Accord commercial initial',
        ]));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $snapshot = SubscriptionOfferSnapshot::query()->sole();
        $catalogueBefore = (int) $snapshot->catalogue_price;
        $reasonBefore = $snapshot->negotiated_rate_reason;

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 22000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $snapshot->refresh();
        $this->assertSame(22000, (int) $subscription->fresh()->amount);
        $this->assertSame(18000, (int) $snapshot->effective_price_at_subscription);
        $this->assertSame($catalogueBefore, (int) $snapshot->catalogue_price);
        $this->assertSame($reasonBefore, $snapshot->negotiated_rate_reason);
    }

    public function test_store_allows_catalogue_amount_without_negotiated_rate_reason(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => (int) $offerVersion->price,
        ]))->assertRedirect();

        $snapshot = SubscriptionOfferSnapshot::query()->sole();
        $this->assertNull($snapshot->negotiated_rate_reason);
    }

    public function test_amount_update_audit_reflects_tariff_change_not_offer_version_change(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 20000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
        ]);

        $log = AuditLog::query()->where('action', 'subscription.updated')->sole();

        $this->assertSame(15000, $log->old_values['amount']);
        $this->assertSame(20000, $log->new_values['amount']);
        $this->assertSame($offerVersion->id, $log->old_values['offer_version_id']);
        $this->assertSame($offerVersion->id, $log->new_values['offer_version_id']);
        $this->assertSame(15000, $log->old_values['catalogue_price']);
        $this->assertSame(15000, $log->new_values['catalogue_price']);
        $this->assertSame(15000, $log->old_values['effective_price_at_subscription']);
        $this->assertSame(15000, $log->new_values['effective_price_at_subscription']);
    }

    public function test_status_transitions_do_not_modify_commercial_snapshot(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 18000,
            'negotiated_rate_reason' => 'Motif initial',
        ]));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $snapshotBefore = SubscriptionOfferSnapshot::query()->sole()->toArray();

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 18000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $subscription->refresh();
        $snapshotAfterGrace = SubscriptionOfferSnapshot::query()->sole()->toArray();
        unset($snapshotBefore['created_at'], $snapshotAfterGrace['created_at']);
        $this->assertSame($snapshotBefore, $snapshotAfterGrace);

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 18000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_SUSPENDED,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
            'grace_period_ends_at' => '2026-11-07 23:59:59',
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        $snapshotAfterSuspended = SubscriptionOfferSnapshot::query()->sole()->toArray();
        unset($snapshotAfterSuspended['created_at']);
        $this->assertSame($snapshotBefore, $snapshotAfterSuspended);
    }

    public function test_terminated_subscription_preserves_commercial_history(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 17000,
            'negotiated_rate_reason' => 'Avant termination',
        ]));

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();
        $snapshotBefore = SubscriptionOfferSnapshot::query()->sole()->toArray();

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 17000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->current_period_start->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end->format('Y-m-d H:i:s'),
            'terminated_at' => '2026-11-30 23:59:59',
        ]);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
        $this->assertSame($offerVersion->id, $subscription->offer_version_id);

        $snapshotAfter = SubscriptionOfferSnapshot::query()->sole()->toArray();
        unset($snapshotBefore['created_at'], $snapshotAfter['created_at']);
        $this->assertSame($snapshotBefore, $snapshotAfter);
    }

    public function test_legacy_subscription_update_keeps_null_offer_version(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'offer_version_id' => null,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-06-01 00:00:00',
            'current_period_start' => '2026-06-01 00:00:00',
            'current_period_end' => '2026-06-30 23:59:59',
            'notes' => 'Legacy',
        ]);

        $this->actingAs($user)->put(route('subscriptions.update', $subscription), [
            'installation_id' => $installation->id,
            'amount' => 16000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'starts_at' => '2026-06-01 00:00:00',
            'current_period_start' => '2026-06-01 00:00:00',
            'current_period_end' => '2026-06-30 23:59:59',
            'grace_period_ends_at' => '2026-07-07 23:59:59',
            'notes' => 'Legacy modifiée',
        ])->assertRedirect();

        $subscription->refresh();
        $this->assertNull($subscription->offer_version_id);
        $this->assertSame(0, SubscriptionOfferSnapshot::query()->count());
        $this->assertSame('Legacy modifiée', $subscription->notes);
    }

    public function test_snapshot_copies_full_commercial_payload_at_creation(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $offerVersion = $this->seedActiveCatalogOfferVersion();
        $offerVersion->load('offer.product');

        $this->actingAs($user)->post(route('subscriptions.store'), $this->subscriptionStorePayload($installation->id, [
            'amount' => 18000,
            'negotiated_rate_reason' => 'Payload complet',
        ]));

        $snapshot = SubscriptionOfferSnapshot::query()->sole();

        $this->assertSame($offerVersion->offer->code, $snapshot->offer_code);
        $this->assertSame($offerVersion->code, $snapshot->offer_version_code);
        $this->assertSame($offerVersion->offer->product->code, $snapshot->product_code);
        $this->assertSame($offerVersion->offer->name, $snapshot->offer_name);
        $this->assertSame((int) $offerVersion->price, (int) $snapshot->catalogue_price);
        $this->assertSame(18000, (int) $snapshot->effective_price_at_subscription);
        $this->assertSame($offerVersion->currency, $snapshot->currency);
        $this->assertSame($offerVersion->billing_cycle, $snapshot->billing_cycle);
        $this->assertSame($offerVersion->inclusions, $snapshot->inclusions);
        $this->assertSame($offerVersion->limitations, $snapshot->limitations);
        $this->assertSame($offerVersion->exclusions, $snapshot->exclusions);
        $this->assertSame($offerVersion->commercial_conditions, $snapshot->commercial_conditions);
        $this->assertSame('Payload complet', $snapshot->negotiated_rate_reason);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeVersion(array $overrides = []): OfferVersion
    {
        $product = Product::query()->create([
            'code' => 'P-'.uniqid(),
            'name' => 'Product',
            'status' => Product::STATUS_ACTIVE,
        ]);
        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'code' => 'O-'.uniqid(),
            'name' => 'Offer',
            'status' => Offer::STATUS_ACTIVE,
        ]);
        $content = CommercialCatalogSeeder::gestBase202601CommercialContent();

        return OfferVersion::query()->create(array_merge([
            'offer_id' => $offer->id,
            'version' => 'v-'.uniqid(),
            'code' => 'V-'.uniqid(),
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
    private function basePayload(int $installationId, OfferVersion $offerVersion): array
    {
        return [
            'installation_id' => $installationId,
            'offer_version_id' => $offerVersion->id,
            'currency' => 'XOF',
            'amount' => 15000,
            'starts_at' => '2026-06-15 00:00:00',
        ];
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
