<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AuditLogService::class);
    }

    public function test_records_authenticated_user_with_ip_and_user_agent(): void
    {
        $user = User::factory()->create();

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.10',
            'HTTP_USER_AGENT' => 'PHPUnit Audit Test Agent',
        ]);

        $this->app->instance('request', $request);
        $this->actingAs($user);

        $log = $this->service->record('client.viewed', result: 'success');

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('client.viewed', $log->action);
        $this->assertSame('success', $log->result);
        $this->assertSame('192.168.1.10', $log->ip_address);
        $this->assertSame('PHPUnit Audit Test Agent', $log->user_agent);
    }

    public function test_records_auditable_model_and_morph_relation(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Société Audit',
            'contact_name' => 'Contact Audit',
            'status' => 'active',
        ]);

        $log = $this->service->record(
            'client.updated',
            auditable: $client,
        );

        $this->assertSame(Client::class, $log->auditable_type);
        $this->assertSame($client->id, $log->auditable_id);
        $this->assertInstanceOf(Client::class, $log->auditable);
        $this->assertTrue($client->is($log->auditable));
    }

    public function test_records_old_and_new_values_as_arrays(): void
    {
        $log = $this->service->record(
            'installation.status_changed',
            oldValues: ['status' => 'active'],
            newValues: ['status' => 'suspended'],
        );

        $fresh = AuditLog::query()->findOrFail($log->id);

        $this->assertSame(['status' => 'active'], $fresh->old_values);
        $this->assertSame(['status' => 'suspended'], $fresh->new_values);
    }

    public function test_records_without_authenticated_user_in_cli_context(): void
    {
        $this->app->forgetInstance('request');

        $log = $this->service->record('subscriptions.sync-lifecycle.completed');

        $this->assertNull($log->user_id);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
        $this->assertSame('subscriptions.sync-lifecycle.completed', $log->action);
    }

    public function test_records_failure_with_error_message(): void
    {
        $log = $this->service->record(
            'payment.renewal_failed',
            result: 'failure',
            errorMessage: 'Paiement déjà utilisé pour le renouvellement.',
        );

        $this->assertSame('failure', $log->result);
        $this->assertSame('Paiement déjà utilisé pour le renouvellement.', $log->error_message);
    }

    public function test_user_relationship_returns_associated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $log = $this->service->record('dashboard.viewed');

        $this->assertNotNull($log->user);
        $this->assertTrue($user->is($log->user));
    }
}
