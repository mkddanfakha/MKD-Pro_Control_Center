<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_creation_is_audited(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();

        $payload = [
            'client_id' => $client->id,
            'name' => 'Installation Audit',
            'subdomain' => 'audit-'.uniqid(),
            'domain' => 'audit.example.com',
            'status' => 'active',
            'version' => '1.0.0',
        ];

        $response = $this->actingAs($user)->post(route('installations.store'), $payload);

        $response->assertRedirect();

        $installation = Installation::query()->where('name', 'Installation Audit')->firstOrFail();

        $log = AuditLog::query()->where('action', 'installation.created')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(Installation::class, $log->auditable_type);
        $this->assertSame($installation->id, $log->auditable_id);
        $this->assertSame('success', $log->result);
        $this->assertNull($log->old_values);
        $this->assertSame($client->id, $log->new_values['client_id']);
        $this->assertSame('Installation Audit', $log->new_values['name']);
        $this->assertSame($installation->subdomain, $log->new_values['subdomain']);
        $this->assertSame('active', $log->new_values['status']);
    }

    public function test_installation_update_is_audited(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Nom initial',
            'subdomain' => 'initial-'.uniqid(),
            'domain' => 'old.example.com',
            'status' => 'active',
            'version' => '0.9.0',
        ]);

        $response = $this->actingAs($user)->put(route('installations.update', $installation), [
            'client_id' => $client->id,
            'name' => 'Nom modifié',
            'subdomain' => $installation->subdomain,
            'domain' => 'new.example.com',
            'status' => 'active',
            'version' => '1.2.0',
        ]);

        $response->assertRedirect(route('installations.show', $installation));

        $installation->refresh();

        $this->assertSame('Nom modifié', $installation->name);
        $this->assertSame('new.example.com', $installation->domain);
        $this->assertSame('1.2.0', $installation->version);

        $log = AuditLog::query()->where('action', 'installation.updated')->sole();

        $this->assertSame($installation->id, $log->auditable_id);
        $this->assertSame('Nom initial', $log->old_values['name']);
        $this->assertSame('Nom modifié', $log->new_values['name']);
        $this->assertSame('old.example.com', $log->old_values['domain']);
        $this->assertSame('new.example.com', $log->new_values['domain']);
        $this->assertSame('0.9.0', $log->old_values['version']);
        $this->assertSame('1.2.0', $log->new_values['version']);
    }

    public function test_installation_status_change_does_not_modify_subscription(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Statut',
            'subdomain' => 'statut-'.uniqid(),
            'status' => 'active',
        ]);

        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put(route('installations.update', $installation), [
            'client_id' => $client->id,
            'name' => $installation->name,
            'subdomain' => $installation->subdomain,
            'status' => 'suspended',
        ]);

        $response->assertRedirect(route('installations.show', $installation));

        $installation->refresh();

        $this->assertSame('suspended', $installation->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(1, Subscription::query()->count());

        $log = AuditLog::query()->where('action', 'installation.updated')->sole();

        $this->assertSame('active', $log->old_values['status']);
        $this->assertSame('suspended', $log->new_values['status']);
    }

    public function test_installation_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation à supprimer',
            'subdomain' => 'delete-'.uniqid(),
            'status' => 'active',
        ]);

        $installationId = $installation->id;

        $response = $this->actingAs($user)->delete(route('installations.destroy', $installation));

        $response->assertRedirect(route('installations.index'));

        $this->assertDatabaseMissing('installations', ['id' => $installationId]);

        $log = AuditLog::query()->where('action', 'installation.deleted')->sole();

        $this->assertSame('success', $log->result);
        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertSame('Installation à supprimer', $log->old_values['name']);
        $this->assertSame($installationId, $log->old_values['id']);
    }

    public function test_installation_index_and_show_do_not_create_audit_logs(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Consultation',
            'subdomain' => 'show-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($user)->get(route('installations.index'))->assertOk();
        $this->actingAs($user)->get(route('installations.show', $installation))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_unauthenticated_users_cannot_create_installations(): void
    {
        $client = $this->makeClient();

        $response = $this->post(route('installations.store'), [
            'client_id' => $client->id,
            'name' => 'Sans auth',
            'subdomain' => 'noauth-'.uniqid(),
            'status' => 'active',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertSame(0, Installation::query()->count());
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
}
