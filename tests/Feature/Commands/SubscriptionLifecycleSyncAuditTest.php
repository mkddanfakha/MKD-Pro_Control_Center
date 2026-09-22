<?php

namespace Tests\Feature\Commands;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SubscriptionLifecycleSyncAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance('request');
    }

    public function test_active_to_grace_period_creates_lifecycle_synced_audit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-11-01 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $log = AuditLog::query()->where('action', 'subscription.lifecycle_synced')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame(Subscription::class, $log->auditable_type);
        $this->assertSame($subscription->id, $log->auditable_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $log->new_values['status']);
        $this->assertSame('2026-10-31 23:59:59', $log->old_values['current_period_end']);
        $this->assertNull($log->old_values['grace_period_ends_at']);
        $this->assertSame('2026-11-07 23:59:59', $log->new_values['grace_period_ends_at']);
        $this->assertNull($log->user_id);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
    }

    public function test_grace_period_to_suspended_creates_lifecycle_synced_audit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->travelTo('2026-11-08 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $log = AuditLog::query()->where('action', 'subscription.lifecycle_synced')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $log->old_values['status']);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $log->new_values['status']);
        $this->assertSame('2026-11-07 23:59:59', $log->old_values['grace_period_ends_at']);
        $this->assertNotNull($log->new_values['suspended_at']);
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.updated')->count());
    }

    public function test_active_subscription_without_transition_creates_no_audit(): void
    {
        $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-10-15 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_grace_period_subscription_without_transition_creates_no_audit(): void
    {
        $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->travelTo('2026-11-05 10:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_suspended_subscription_is_not_audited(): void
    {
        $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        $this->travelTo('2026-12-01 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_terminated_subscription_is_not_audited(): void
    {
        $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_sync_failure_creates_lifecycle_sync_failed_audit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->travelTo('2026-10-15 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $log = AuditLog::query()->where('action', 'subscription.lifecycle_sync_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame($subscription->id, $log->auditable_id);
        $this->assertSame(Subscription::class, $log->auditable_type);
        $this->assertNotNull($log->error_message);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_sync_failed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());
    }

    public function test_lifecycle_sync_does_not_modify_installation_status(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $installation->update(['status' => 'active']);

        $this->travelTo('2026-11-01 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame('active', $installation->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());
    }

    public function test_case_a_second_immediate_run_after_active_to_grace_creates_no_new_audit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-11-01 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());
    }

    public function test_case_c_second_run_after_grace_to_suspended_creates_no_new_audit(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->travelTo('2026-11-08 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());

        Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.lifecycle_sync_failed')->count());
    }

    public function test_lifecycle_synced_new_values_match_persisted_subscription_state(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->travelTo('2026-11-08 00:00:00');

        Artisan::call('subscriptions:sync-lifecycle');

        $persisted = $subscription->fresh();
        $log = AuditLog::query()->where('action', 'subscription.lifecycle_synced')->sole();

        $this->assertSame($persisted->status, $log->new_values['status']);
        $this->assertSame(
            $persisted->current_period_end->format('Y-m-d H:i:s'),
            $log->new_values['current_period_end'],
        );
        $this->assertSame(
            $persisted->grace_period_ends_at->format('Y-m-d H:i:s'),
            $log->new_values['grace_period_ends_at'],
        );
        $this->assertSame(
            $persisted->suspended_at->format('Y-m-d H:i:s'),
            $log->new_values['suspended_at'],
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $log->new_values['suspended_at']);
    }

    public function test_failure_on_one_subscription_does_not_prevent_lifecycle_sync_audit_on_another(): void
    {
        $valid = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $invalid = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->travelTo('2026-10-15 12:00:00');

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');

        $this->assertSame(1, $exitCode);
        $this->assertSame(Subscription::STATUS_ACTIVE, $valid->fresh()->status);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $invalid->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'subscription.lifecycle_synced')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.lifecycle_sync_failed')->count());
        $this->assertSame($invalid->id, AuditLog::query()->where('action', 'subscription.lifecycle_sync_failed')->value('auditable_id'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Audit Lifecycle',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'lifecycle-audit-'.uniqid(),
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
