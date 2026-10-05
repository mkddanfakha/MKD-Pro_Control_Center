<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('audit-logs.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_index_page(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog(['action' => 'client.created']);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLogs/Index')
                ->has('auditLogs.data', 1)
                ->has('filters'));
    }

    public function test_pagination_uses_twenty_five_items_per_page(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($i = 0; $i < 26; $i++) {
            $this->makeAuditLog([
                'action' => 'module.created',
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.per_page', 25)
                ->has('auditLogs.data', 25));

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 1));
    }

    public function test_logs_ordered_by_created_at_then_id_descending(): void
    {
        $user = $this->controlCenterAdminUser();

        $older = $this->makeAuditLog([
            'action' => 'client.created',
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $newer = $this->makeAuditLog([
            'action' => 'client.updated',
            'created_at' => '2026-02-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.data.0.id', $newer->id)
                ->where('auditLogs.data.1.id', $older->id));
    }

    public function test_filter_by_action(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog(['action' => 'client.created']);
        $this->makeAuditLog(['action' => 'payment.created']);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['action' => 'payment.created']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.action', 'payment.created')
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'payment.created'));
    }

    public function test_filter_by_auditable_type(): void
    {
        $admin = $this->controlCenterAdminUser();
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog([
            'action' => 'client.created',
            'auditable_type' => Client::class,
            'auditable_id' => 1,
        ]);
        $this->makeAuditLog([
            'action' => 'auth.login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['auditable_type' => Client::class]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.auditable_type', Client::class));
    }

    public function test_filter_by_auditable_id(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $this->makeAuditLog([
            'action' => 'subscription.credit_consumed',
            'auditable_type' => (new Subscription)->getMorphClass(),
            'auditable_id' => $subscription->id,
        ]);
        $this->makeAuditLog([
            'action' => 'subscription.credit_consumed',
            'auditable_type' => (new Subscription)->getMorphClass(),
            'auditable_id' => $subscription->id + 999,
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['auditable_id' => $subscription->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.auditable_id', $subscription->id)
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.auditable_id', $subscription->id));
    }

    public function test_filter_by_date_range(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog([
            'action' => 'client.created',
            'created_at' => '2026-01-15 08:00:00',
        ]);
        $this->makeAuditLog([
            'action' => 'client.updated',
            'created_at' => '2026-02-15 08:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['date_from' => '2026-02-01']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'client.updated'));

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['date_to' => '2026-01-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'client.created'));
    }

    public function test_search_matches_action_or_error_message(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog(['action' => 'payment.renewal_failed', 'error_message' => null]);
        $this->makeAuditLog([
            'action' => 'auth.login_failed',
            'result' => 'failure',
            'error_message' => 'Identifiants invalides pour renewal',
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['search' => 'renewal']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 2));
    }

    public function test_known_payment_and_subscription_events_are_serialized(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $events = [
            'payment.created',
            'subscription.credit_consumed',
            'subscription.lifecycle_synced',
            'payment.renewal_applied',
            'payment.renewal_failed',
        ];

        foreach ($events as $action) {
            $this->makeAuditLog([
                'action' => $action,
                'auditable_type' => (new Subscription)->getMorphClass(),
                'auditable_id' => $subscription->id,
                'new_values' => ['subscription' => ['status' => 'active', 'id' => $subscription->id]],
            ]);
        }

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('auditLogs.data', count($events)));

        foreach ($events as $action) {
            $this->actingAs($user)
                ->get(route('audit-logs.index', ['action' => $action]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('auditLogs.data.0.action', $action));
        }
    }

    public function test_subscription_subject_link_when_record_exists(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();

        $this->makeAuditLog([
            'action' => 'subscription.lifecycle_synced',
            'auditable_type' => (new Subscription)->getMorphClass(),
            'auditable_id' => $subscription->id,
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.data.0.subject.url', route('subscriptions.show', $subscription))
                ->where('auditLogs.data.0.subject.available', true));
    }

    public function test_payment_subject_link_uses_payments_index_with_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->makeAuditLog([
            'action' => 'payment.created',
            'auditable_type' => (new Payment)->getMorphClass(),
            'auditable_id' => $payment->id,
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'auditLogs.data.0.subject.url',
                    route('payments.index', ['subscription_id' => $subscription->id]),
                )
                ->where('auditLogs.data.0.subject.available', true));
    }

    public function test_installation_and_client_subject_links_when_records_exist(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Audit Client',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Site Audit',
            'subdomain' => 'audit-'.uniqid(),
            'status' => 'active',
        ]);

        $this->makeAuditLog([
            'action' => 'installation.created',
            'auditable_type' => (new Installation)->getMorphClass(),
            'auditable_id' => $installation->id,
        ]);
        $this->makeAuditLog([
            'action' => 'client.created',
            'auditable_type' => (new Client)->getMorphClass(),
            'auditable_id' => $client->id,
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.data.0.subject.url', route('clients.show', $client))
                ->where('auditLogs.data.1.subject.url', route('installations.show', $installation)));
    }

    public function test_missing_audited_subject_does_not_cause_not_found(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog([
            'action' => 'subscription.lifecycle_synced',
            'auditable_type' => (new Subscription)->getMorphClass(),
            'auditable_id' => 999_999,
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.data.0.subject.url', null)
                ->where('auditLogs.data.0.subject.available', false)
                ->where('auditLogs.data.0.subject.label', fn ($label) => str_contains($label, 'Objet indisponible')));
    }

    public function test_sensitive_values_are_masked_in_detail(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->makeAuditLog([
            'action' => 'installation.updated',
            'new_values' => [
                'name' => 'Public',
                'database_name' => 'secret_db',
                'database_host' => '10.0.0.1',
                'password' => 'hunter2',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.data.0.detail.new_values.name', 'Public')
                ->where('auditLogs.data.0.detail.new_values.database_name', '[masqué]')
                ->where('auditLogs.data.0.detail.new_values.database_host', '[masqué]')
                ->where('auditLogs.data.0.detail.new_values.password', '[masqué]'));
    }

    public function test_index_is_read_only_and_does_not_mutate_audit_rows(): void
    {
        $user = $this->controlCenterAdminUser();

        $log = $this->makeAuditLog([
            'action' => 'installation.created',
            'new_values' => ['name' => 'Stable'],
        ]);

        $before = AuditLog::query()->count();

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk();

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame('Stable', AuditLog::query()->findOrFail($log->id)->new_values['name']);
    }

    public function test_only_get_routes_exist_for_audit_logs(): void
    {
        $auditLogRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains($route->getName() ?? '', 'audit-logs'),
        );

        $this->assertTrue($auditLogRoutes->isNotEmpty());

        foreach ($auditLogRoutes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame('audit-logs.index', $route->getName());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeAuditLog(array $attributes = []): AuditLog
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $log = AuditLog::query()->create(array_merge([
            'action' => 'client.created',
            'result' => 'success',
        ], $attributes));

        if ($createdAt !== null) {
            DB::table('audit_logs')
                ->where('id', $log->id)
                ->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            $log->refresh();
        }

        return $log;
    }

    private function makeSubscription(): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Audit Co',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Audit Install',
            'subdomain' => 'audit-sub-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-07-01 00:00:00',
        ]);
    }
}
