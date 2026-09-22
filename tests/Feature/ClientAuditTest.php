<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_creation_is_audited(): void
    {
        $user = User::factory()->create();

        $payload = [
            'company_name' => 'Entreprise Audit',
            'contact_name' => 'Contact Audit',
            'phone' => '0102030405',
            'email' => 'audit@example.com',
            'address' => '1 rue Test',
            'city' => 'Paris',
            'country' => 'France',
            'status' => 'active',
            'notes' => 'Note initiale',
        ];

        $response = $this->actingAs($user)->post(route('clients.store'), $payload);

        $response->assertRedirect(route('clients.index'));

        $client = Client::query()->where('company_name', 'Entreprise Audit')->firstOrFail();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'company_name' => 'Entreprise Audit',
        ]);

        $log = AuditLog::query()->where('action', 'client.created')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(Client::class, $log->auditable_type);
        $this->assertSame($client->id, $log->auditable_id);
        $this->assertSame('success', $log->result);
        $this->assertNull($log->old_values);
        $this->assertSame('Entreprise Audit', $log->new_values['company_name']);
        $this->assertSame('Contact Audit', $log->new_values['contact_name']);
        $this->assertSame('active', $log->new_values['status']);
        $this->assertSame($client->id, $log->new_values['id']);
    }

    public function test_client_update_is_audited(): void
    {
        $user = User::factory()->create();

        $client = Client::query()->create([
            'company_name' => 'Ancienne société',
            'contact_name' => 'Ancien contact',
            'status' => 'active',
            'notes' => 'Ancienne note',
        ]);

        $response = $this->actingAs($user)->put(route('clients.update', $client), [
            'company_name' => 'Nouvelle société',
            'contact_name' => 'Nouveau contact',
            'phone' => null,
            'email' => null,
            'address' => null,
            'city' => null,
            'country' => null,
            'status' => 'inactive',
            'notes' => 'Nouvelle note',
        ]);

        $response->assertRedirect(route('clients.show', $client));

        $client->refresh();

        $this->assertSame('Nouvelle société', $client->company_name);
        $this->assertSame('inactive', $client->status);

        $log = AuditLog::query()->where('action', 'client.updated')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($client->id, $log->auditable_id);
        $this->assertSame('Ancienne société', $log->old_values['company_name']);
        $this->assertSame('Nouvelle société', $log->new_values['company_name']);
        $this->assertSame('active', $log->old_values['status']);
        $this->assertSame('inactive', $log->new_values['status']);
    }

    public function test_client_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = User::factory()->create();

        $client = Client::query()->create([
            'company_name' => 'Société à supprimer',
            'contact_name' => 'Contact Suppression',
            'status' => 'active',
        ]);

        $clientId = $client->id;

        $response = $this->actingAs($user)->delete(route('clients.destroy', $client));

        $response->assertRedirect(route('clients.index'));

        $this->assertDatabaseMissing('clients', ['id' => $clientId]);

        $log = AuditLog::query()->where('action', 'client.deleted')->sole();

        $this->assertSame('success', $log->result);
        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertSame('Société à supprimer', $log->old_values['company_name']);
        $this->assertSame($clientId, $log->old_values['id']);
    }

    public function test_client_index_and_show_do_not_create_audit_logs(): void
    {
        $user = User::factory()->create();

        $client = Client::query()->create([
            'company_name' => 'Consultation seule',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $this->actingAs($user)->get(route('clients.index'))->assertOk();
        $this->actingAs($user)->get(route('clients.show', $client))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_unauthenticated_users_cannot_create_clients(): void
    {
        $response = $this->post(route('clients.store'), [
            'company_name' => 'Sans auth',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }
}
