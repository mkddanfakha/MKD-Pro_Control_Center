<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionPeriodException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Http\Controllers\SubscriptionController;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_active_subscription_with_initial_period_from_starts_at(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'notes' => 'Abonnement initial',
        ]);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('2026-10-01 00:00:00', $subscription->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $log = AuditLog::query()->where('action', 'subscription.created')->sole();

        $this->assertSame(Subscription::STATUS_ACTIVE, $log->new_values['status']);
        $this->assertSame('2026-10-01 00:00:00', $log->new_values['starts_at']);
        $this->assertSame('2026-10-01 00:00:00', $log->new_values['current_period_start']);
        $this->assertSame('2026-10-31 23:59:59', $log->new_values['current_period_end']);
        $this->assertSame((int) config('subscriptions.default_monthly_amount'), (int) $subscription->amount);
    }

    public function test_store_uses_explicit_amount_when_provided_instead_of_default(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'amount' => 20000,
        ]);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->assertSame(20000, (int) $subscription->amount);
    }

    public function test_store_rejects_creation_without_starts_at(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
        ]);

        $response->assertSessionHasErrors('starts_at');

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_store_forces_active_status_when_grace_period_is_sent(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_store_forces_active_status_when_suspended_is_sent(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_store_forces_active_status_when_terminated_is_sent(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    public function test_store_ignores_manual_period_fields_and_uses_create_initial_period(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-11-01 00:00:00',
            'current_period_start' => '2020-01-01 00:00:00',
            'current_period_end' => '2020-01-31 23:59:59',
        ]);

        $response->assertRedirect();

        $subscription = Subscription::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->assertSame('2026-11-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:59:59', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_store_allows_creation_when_installation_has_no_subscription(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Subscription::query()->where('installation_id', $installation->id)->count());
    }

    public function test_store_rejects_creation_when_installation_has_active_subscription(): void
    {
        $this->assertStoreRejectsWhenNonTerminatedSubscriptionExists(Subscription::STATUS_ACTIVE);
    }

    public function test_store_rejects_creation_when_installation_has_grace_period_subscription(): void
    {
        $this->assertStoreRejectsWhenNonTerminatedSubscriptionExists(Subscription::STATUS_GRACE_PERIOD);
    }

    public function test_store_rejects_creation_when_installation_has_suspended_subscription(): void
    {
        $this->assertStoreRejectsWhenNonTerminatedSubscriptionExists(Subscription::STATUS_SUSPENDED);
    }

    public function test_store_allows_creation_when_installation_has_only_terminated_subscription(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $this->makeExistingSubscription($installation, Subscription::STATUS_TERMINATED, [
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
        ]);

        $response->assertRedirect();

        $subscriptions = Subscription::query()
            ->where('installation_id', $installation->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $subscriptions);
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscriptions[0]->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscriptions[1]->status);
        $this->assertNotNull($subscriptions[1]->current_period_start);
        $this->assertNotNull($subscriptions[1]->current_period_end);

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.created')->count());
    }

    public function test_store_allows_creation_when_installation_has_multiple_terminated_subscriptions(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $this->makeExistingSubscription($installation, Subscription::STATUS_TERMINATED);
        $this->makeExistingSubscription($installation, Subscription::STATUS_TERMINATED);

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-10-01 00:00:00',
        ]);

        $response->assertRedirect();
        $this->assertSame(3, Subscription::query()->where('installation_id', $installation->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.created')->count());
    }

    public function test_store_rejects_creation_when_terminated_and_active_subscriptions_exist(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $this->makeExistingSubscription($installation, Subscription::STATUS_TERMINATED);
        $this->makeExistingSubscription($installation, Subscription::STATUS_ACTIVE);

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-12-01 00:00:00',
        ]);

        $response->assertSessionHasErrors('installation_id');
        $this->assertSame(2, Subscription::query()->where('installation_id', $installation->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.created')->count());
    }

    public function test_store_rolls_back_subscription_and_audit_when_initial_period_fails(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->mock(SubscriptionService::class, function ($mock): void {
            $mock->shouldReceive('createInitialPeriod')
                ->once()
                ->andThrow(new SubscriptionPeriodException('Échec simulé de la période initiale.'));
        });

        try {
            $this->withoutExceptionHandling();

            $this->actingAs($user)->post(route('subscriptions.store'), [
                'installation_id' => $installation->id,
                'starts_at' => '2026-10-01 00:00:00',
            ]);

            $this->fail('Une SubscriptionPeriodException était attendue.');
        } catch (SubscriptionPeriodException) {
            // Rollback attendu.
        }

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_store_maps_unique_constraint_violation_to_installation_id_validation_error(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $this->makeExistingSubscription($installation, Subscription::STATUS_ACTIVE);

        $subscriptionCount = Subscription::query()->where('installation_id', $installation->id)->count();
        $auditCountBefore = AuditLog::query()->count();

        $controller = new class(app(AuditLogService::class), app(SubscriptionService::class)) extends SubscriptionController
        {
            protected function assertInstallationAllowsNewSubscription(int $installationId): void
            {
                // Simule une course concurrente où la validation métier n'a pas vu l'abonnement existant.
            }
        };

        $this->app->instance(SubscriptionController::class, $controller);

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-12-01 00:00:00',
        ]);

        $response->assertSessionHasErrors([
            'installation_id' => 'Cette installation possède déjà un abonnement non terminé.',
        ]);

        $this->assertSame($subscriptionCount, Subscription::query()->where('installation_id', $installation->id)->count());
        $this->assertSame($auditCountBefore, AuditLog::query()->count());
    }

    private function assertStoreRejectsWhenNonTerminatedSubscriptionExists(string $status): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $this->makeExistingSubscription($installation, $status);

        $response = $this->actingAs($user)->post(route('subscriptions.store'), [
            'installation_id' => $installation->id,
            'starts_at' => '2026-12-01 00:00:00',
        ]);

        $response->assertSessionHasErrors([
            'installation_id' => 'Cette installation possède déjà un abonnement non terminé.',
        ]);

        $this->assertSame(1, Subscription::query()->where('installation_id', $installation->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.created')->count());
    }

    private function makeClient(): Client
    {
        return Client::query()->create([
            'company_name' => 'Client Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);
    }

    private function makeInstallation(): Installation
    {
        return Installation::query()->create([
            'client_id' => $this->makeClient()->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeExistingSubscription(Installation $installation, string $status, array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => $status,
        ], $attributes));
    }
}
