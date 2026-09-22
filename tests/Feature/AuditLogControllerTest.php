<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_audit_logs_index(): void
    {
        $user = User::factory()->create();

        $this->makeAuditLog(['action' => 'client.created']);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLogs/Index', false)
                ->has('auditLogs.data', 1)
                ->has('filters'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('audit-logs.index'))
            ->assertRedirect(route('login'));
    }

    public function test_audit_logs_are_ordered_newest_first(): void
    {
        $user = User::factory()->create();

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

    public function test_audit_logs_are_paginated(): void
    {
        $user = User::factory()->create();

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

    public function test_filter_by_action(): void
    {
        $user = User::factory()->create();

        $this->makeAuditLog(['action' => 'client.created']);
        $this->makeAuditLog(['action' => 'payment.created']);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['action' => 'client.created']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.action', 'client.created')
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'client.created'));
    }

    public function test_filter_by_result(): void
    {
        $user = User::factory()->create();

        $this->makeAuditLog(['action' => 'auth.login', 'result' => 'success']);
        $this->makeAuditLog(['action' => 'auth.login_failed', 'result' => 'failure']);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['result' => 'failure']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.result', 'failure')
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.result', 'failure'));
    }

    public function test_filter_by_user_id(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeAuditLog(['action' => 'client.created', 'user_id' => $user->id]);
        $this->makeAuditLog(['action' => 'client.created', 'user_id' => $other->id]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['user_id' => $user->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.user_id', $user->id)
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.user_id', $user->id));
    }

    public function test_filter_by_auditable_type(): void
    {
        $user = User::factory()->create();

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

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['auditable_type' => Client::class]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.auditable_type', Client::class)
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.auditable_type', Client::class));
    }

    public function test_filter_by_date_from(): void
    {
        $user = User::factory()->create();

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
                ->where('filters.date_from', '2026-02-01')
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'client.updated'));
    }

    public function test_filter_by_date_to(): void
    {
        $user = User::factory()->create();

        $this->makeAuditLog([
            'action' => 'client.created',
            'created_at' => '2026-01-15 08:00:00',
        ]);
        $this->makeAuditLog([
            'action' => 'client.updated',
            'created_at' => '2026-02-15 08:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', ['date_to' => '2026-01-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.date_to', '2026-01-31')
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'client.created'));
    }

    public function test_multiple_filters_can_be_combined(): void
    {
        $user = User::factory()->create();

        $this->makeAuditLog([
            'action' => 'client.created',
            'result' => 'success',
            'user_id' => $user->id,
            'created_at' => '2026-03-10 12:00:00',
        ]);
        $this->makeAuditLog([
            'action' => 'client.created',
            'result' => 'failure',
            'user_id' => $user->id,
            'created_at' => '2026-03-10 12:00:00',
        ]);
        $this->makeAuditLog([
            'action' => 'payment.created',
            'result' => 'success',
            'user_id' => $user->id,
            'created_at' => '2026-03-10 12:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', [
                'action' => 'client.created',
                'result' => 'success',
                'user_id' => $user->id,
                'date_from' => '2026-03-01',
                'date_to' => '2026-03-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.action', 'client.created')
                ->where('auditLogs.data.0.result', 'success'));
    }

    public function test_old_and_new_values_are_included_in_response(): void
    {
        $user = User::factory()->create();

        $this->makeAuditLog([
            'action' => 'client.updated',
            'old_values' => ['name' => 'Avant'],
            'new_values' => ['name' => 'Après'],
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditLogs.data.0.old_values.name', 'Avant')
                ->where('auditLogs.data.0.new_values.name', 'Après'));
    }

    public function test_viewing_audit_logs_does_not_modify_records(): void
    {
        $user = User::factory()->create();

        $log = $this->makeAuditLog([
            'action' => 'installation.created',
            'new_values' => ['name' => 'Site A'],
        ]);

        $before = AuditLog::query()->count();

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk();

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame(
            'Site A',
            AuditLog::query()->findOrFail($log->id)->new_values['name'],
        );
    }

    public function test_no_audit_log_write_routes_are_registered(): void
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
}
