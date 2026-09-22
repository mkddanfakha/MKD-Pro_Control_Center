<?php

namespace Tests\Unit;

use App\Exceptions\Subscription\SubscriptionLifecycleException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLifecycleSyncTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    public function test_active_subscription_with_future_period_stays_active(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-10-15 12:00:00'),
        );

        $this->assertSame(Subscription::STATUS_ACTIVE, $synced->status);
        $this->assertNull($synced->grace_period_ends_at);
    }

    public function test_active_subscription_with_expired_period_moves_to_grace_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-01 00:00:00'),
        );

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $synced->status);
    }

    public function test_active_subscription_sets_grace_period_ends_at_on_transition(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-01 08:00:00'),
        );

        $this->assertSame('2026-11-07 23:59:59', $synced->grace_period_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_grace_period_subscription_with_valid_grace_stays_unchanged(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-05 10:00:00'),
        );

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $synced->status);
        $this->assertSame('2026-11-07 23:59:59', $synced->grace_period_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_grace_period_subscription_with_expired_grace_moves_to_suspended(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $now = Carbon::parse('2026-11-08 00:00:00');

        $synced = $this->service->syncLifecycle($subscription, $now);

        $this->assertSame(Subscription::STATUS_SUSPENDED, $synced->status);
        $this->assertSame($now->format('Y-m-d H:i:s'), $synced->suspended_at->format('Y-m-d H:i:s'));
    }

    public function test_suspended_subscription_stays_suspended(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-12-01 00:00:00'),
        );

        $this->assertSame(Subscription::STATUS_SUSPENDED, $synced->status);
        $this->assertSame('2026-11-08 00:00:00', $synced->suspended_at->format('Y-m-d H:i:s'));
    }

    public function test_terminated_subscription_stays_terminated(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-12-01 00:00:00'),
        );

        $this->assertSame(Subscription::STATUS_TERMINATED, $synced->status);
        $this->assertSame('2026-11-01 00:00:00', $synced->terminated_at->format('Y-m-d H:i:s'));
    }

    public function test_sync_lifecycle_is_idempotent_with_same_now(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $now = Carbon::parse('2026-11-02 12:00:00');

        $first = $this->service->syncLifecycle($subscription, $now);
        $second = $this->service->syncLifecycle($first, $now);

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $first->status);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $second->status);
        $this->assertSame(
            $first->grace_period_ends_at->format('Y-m-d H:i:s'),
            $second->grace_period_ends_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_active_subscription_without_current_period_end_throws(): void
    {
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->expectException(SubscriptionLifecycleException::class);

        $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-01 00:00:00'),
        );
    }

    public function test_grace_period_subscription_without_grace_period_ends_at_throws(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->expectException(SubscriptionLifecycleException::class);

        $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-05 00:00:00'),
        );
    }

    public function test_grace_period_with_grace_ends_before_period_end_throws(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-10-30 23:59:59',
        ]);

        $this->expectException(SubscriptionLifecycleException::class);

        $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-01 00:00:00'),
        );
    }

    public function test_unknown_subscription_status_throws(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => 'unknown',
        ]);

        $this->expectException(SubscriptionLifecycleException::class);

        $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-01 00:00:00'),
        );
    }

    public function test_suspended_at_is_set_when_entering_suspended_status(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
            'suspended_at' => null,
        ]);

        $now = Carbon::parse('2026-11-09 09:30:00');

        $synced = $this->service->syncLifecycle($subscription, $now);

        $this->assertSame(Subscription::STATUS_SUSPENDED, $synced->status);
        $this->assertNotNull($synced->suspended_at);
        $this->assertSame('2026-11-09 09:30:00', $synced->suspended_at->format('Y-m-d H:i:s'));
    }

    public function test_active_to_grace_period_does_not_modify_installation_status(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $installation->update(['status' => 'active']);

        $installationBefore = $installation->fresh()->only(['status', 'suspended_at', 'terminated_at']);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-01 00:00:00'),
        );

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $synced->status);
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertSame(
            $installationBefore,
            $installation->fresh()->only(['status', 'suspended_at', 'terminated_at']),
        );
    }

    public function test_grace_period_to_suspended_does_not_modify_installation_status(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $installation->update(['status' => 'active']);

        $installationBefore = $installation->fresh()->only(['status', 'suspended_at', 'terminated_at']);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-11-08 00:00:00'),
        );

        $this->assertSame(Subscription::STATUS_SUSPENDED, $synced->status);
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertSame(
            $installationBefore,
            $installation->fresh()->only(['status', 'suspended_at', 'terminated_at']),
        );
    }

    public function test_suspended_subscription_sync_does_not_modify_installation_status(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $installation->update(['status' => 'active']);

        $installationBefore = $installation->fresh()->only(['status', 'suspended_at', 'terminated_at']);

        $synced = $this->service->syncLifecycle(
            $subscription,
            Carbon::parse('2026-12-01 00:00:00'),
        );

        $this->assertSame(Subscription::STATUS_SUSPENDED, $synced->status);
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertSame(
            $installationBefore,
            $installation->fresh()->only(['status', 'suspended_at', 'terminated_at']),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
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
