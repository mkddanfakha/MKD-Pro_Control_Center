<?php

namespace Tests\Feature;

use App\Exceptions\Subscription\SubscriptionPeriodException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
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
}
