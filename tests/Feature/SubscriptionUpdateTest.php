<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminated_cannot_be_reactivated_to_active(): void
    {
        $this->assertTerminatedReactivationRejected(Subscription::STATUS_ACTIVE);
    }

    public function test_terminated_cannot_be_reactivated_to_grace_period(): void
    {
        $this->assertTerminatedReactivationRejected(Subscription::STATUS_GRACE_PERIOD);
    }

    public function test_terminated_cannot_be_reactivated_to_suspended(): void
    {
        $this->assertTerminatedReactivationRejected(Subscription::STATUS_SUSPENDED);
    }

    public function test_terminated_can_remain_terminated_with_valid_payload(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
            'notes' => 'Clôturé',
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => Subscription::STATUS_TERMINATED,
                'notes' => 'Clôturé — note mise à jour',
            ]),
        );

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
        $this->assertSame('Clôturé — note mise à jour', $subscription->notes);
    }

    public function test_terminated_reactivation_refusal_does_not_create_audit_log(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => Subscription::STATUS_ACTIVE,
            ]),
        );

        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.updated')->count());
    }

    public function test_update_rejects_second_non_terminated_via_status_change_from_terminated(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $terminated = $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-08-01 00:00:00',
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $terminated),
            $this->validUpdatePayload($terminated, [
                'status' => Subscription::STATUS_ACTIVE,
            ]),
        );

        $response->assertSessionHasErrors('status');
        $terminated->refresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $terminated->status);
    }

    public function test_update_rejects_when_grace_period_conflicts_with_another_non_terminated(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $grace = $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $grace),
            $this->validUpdatePayload($grace, [
                'notes' => 'Tentative de modification',
            ]),
        );

        $response->assertSessionHasErrors('installation_id');
    }

    public function test_update_rejects_when_suspended_conflicts_with_another_non_terminated(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $suspended = $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-01 00:00:00',
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $suspended),
            $this->validUpdatePayload($suspended, [
                'notes' => 'Tentative de modification',
            ]),
        );

        $response->assertSessionHasErrors('installation_id');
    }

    public function test_update_rejects_installation_move_when_target_has_non_terminated(): void
    {
        $user = User::factory()->create();
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();

        $moving = $this->makeSubscriptionOnInstallation($installationA, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->makeSubscriptionOnInstallation($installationB, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $moving),
            $this->validUpdatePayload($moving, [
                'installation_id' => $installationB->id,
            ]),
        );

        $response->assertSessionHasErrors('installation_id');

        $moving->refresh();
        $this->assertSame($installationA->id, $moving->installation_id);
    }

    public function test_update_allows_installation_move_when_target_has_only_terminated(): void
    {
        $user = User::factory()->create();
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();

        $moving = $this->makeSubscriptionOnInstallation($installationA, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->makeSubscriptionOnInstallation($installationB, [
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-07-01 00:00:00',
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $moving),
            $this->validUpdatePayload($moving, [
                'installation_id' => $installationB->id,
            ]),
        );

        $response->assertRedirect(route('subscriptions.show', $moving));

        $moving->refresh();
        $this->assertSame($installationB->id, $moving->installation_id);
    }

    public function test_update_rejects_current_period_start_without_end(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'current_period_start' => '2026-10-01 00:00:00',
                'current_period_end' => null,
            ]),
        );

        $response->assertSessionHasErrors('current_period_end');
    }

    public function test_update_rejects_current_period_end_without_start(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'current_period_start' => null,
                'current_period_end' => '2026-10-31 23:59:59',
            ]),
        );

        $response->assertSessionHasErrors('current_period_start');
    }

    public function test_update_allows_both_period_dates_when_valid(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'current_period_start' => '2026-10-01 00:00:00',
                'current_period_end' => '2026-10-31 23:59:59',
            ]),
        );

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();

        $this->assertSame('2026-10-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_update_rejects_period_end_before_period_start(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'current_period_start' => '2026-10-31 00:00:00',
                'current_period_end' => '2026-10-01 00:00:00',
            ]),
        );

        $response->assertSessionHasErrors('current_period_end');
    }

    public function test_active_can_transition_to_suspended(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => Subscription::STATUS_SUSPENDED,
                'suspended_at' => '2026-11-08 00:00:00',
            ]),
        );

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->status);
    }

    public function test_active_can_transition_to_terminated(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => Subscription::STATUS_TERMINATED,
                'terminated_at' => '2026-12-01 00:00:00',
            ]),
        );

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
    }

    public function test_active_amount_and_notes_can_be_updated(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 15000,
            'notes' => 'Avant',
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'amount' => 18000,
                'notes' => 'Après',
            ]),
        );

        $response->assertRedirect(route('subscriptions.show', $subscription));

        $subscription->refresh();
        $this->assertSame(18000, $subscription->amount);
        $this->assertSame('Après', $subscription->notes);
    }

    public function test_successful_update_creates_exactly_one_audit_log(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'notes' => 'Audit test',
            ]),
        );

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.updated')->count());
    }

    public function test_update_does_not_modify_installation_status(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $installationStatusBefore = $installation->status;

        $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => Subscription::STATUS_SUSPENDED,
                'suspended_at' => '2026-11-08 00:00:00',
            ]),
        );

        $installation->refresh();
        $this->assertSame($installationStatusBefore, $installation->status);
    }

    public function test_rejected_update_does_not_modify_subscription_attributes(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
            'notes' => 'Inchangé',
        ]);

        $before = $subscription->fresh()->only(['status', 'notes', 'installation_id']);

        $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => Subscription::STATUS_ACTIVE,
                'notes' => 'Ne doit pas persister',
            ]),
        );

        $this->assertSame($before, $subscription->fresh()->only(['status', 'notes', 'installation_id']));
    }

    public function test_rejected_update_does_not_change_subscription_count(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $terminated = $this->makeSubscriptionOnInstallation($installation, [
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $countBefore = Subscription::query()->count();

        $this->actingAs($user)->put(
            route('subscriptions.update', $terminated),
            $this->validUpdatePayload($terminated, [
                'status' => Subscription::STATUS_ACTIVE,
            ]),
        );

        $this->assertSame($countBefore, Subscription::query()->count());
    }

    public function test_rejected_update_does_not_modify_installation_record(): void
    {
        $user = User::factory()->create();
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();

        $moving = $this->makeSubscriptionOnInstallation($installationA, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->makeSubscriptionOnInstallation($installationB, [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $installationBefore = $installationA->fresh()->only(['status', 'name', 'subdomain']);

        $this->actingAs($user)->put(
            route('subscriptions.update', $moving),
            $this->validUpdatePayload($moving, [
                'installation_id' => $installationB->id,
            ]),
        );

        $this->assertSame($installationBefore, $installationA->fresh()->only(['status', 'name', 'subdomain']));
    }

    private function assertTerminatedReactivationRejected(string $targetStatus): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $response = $this->actingAs($user)->put(
            route('subscriptions.update', $subscription),
            $this->validUpdatePayload($subscription, [
                'status' => $targetStatus,
            ]),
        );

        $response->assertSessionHasErrors([
            'status' => 'Un abonnement terminé ne peut pas être réactivé.',
        ]);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUpdatePayload(Subscription $subscription, array $overrides = []): array
    {
        return array_merge([
            'installation_id' => $subscription->installation_id,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => $subscription->status,
            'notes' => $subscription->notes,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $installation = $this->makeInstallation();

        return $this->makeSubscriptionOnInstallation($installation, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscriptionOnInstallation(Installation $installation, array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
