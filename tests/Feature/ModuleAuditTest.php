<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_creation_is_audited(): void
    {
        $user = User::factory()->create();

        $payload = [
            'name' => 'Module Audit',
            'slug' => 'module-audit-'.uniqid(),
            'description' => 'Description initiale',
            'version' => '1.0.0',
            'price' => 5000,
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 10,
        ];

        $response = $this->actingAs($user)->post(route('modules.store'), $payload);

        $response->assertRedirect();

        $module = Module::query()->where('slug', $payload['slug'])->firstOrFail();

        $log = AuditLog::query()->where('action', 'module.created')->sole();

        $this->assertNull($log->old_values);
        $this->assertNotNull($log->new_values);
        $this->assertSame('Module Audit', $log->new_values['name']);
        $this->assertSame($payload['slug'], $log->new_values['slug']);
        $this->assertSame(5000, $log->new_values['price']);
        $this->assertSame(Module::STATUS_ACTIVE, $log->new_values['status']);
        $this->assertSame($module->id, $log->auditable_id);
    }

    public function test_module_update_is_audited(): void
    {
        $user = User::factory()->create();

        $module = Module::query()->create([
            'name' => 'Nom initial',
            'slug' => 'initial-'.uniqid(),
            'description' => 'Description A',
            'version' => '0.9.0',
            'price' => 1000,
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->put(route('modules.update', $module), [
            'name' => 'Nom modifié',
            'slug' => $module->slug,
            'description' => 'Description B',
            'version' => '2.0.0',
            'price' => 2500,
            'currency' => 'XOF',
            'status' => Module::STATUS_INACTIVE,
            'sort_order' => 5,
        ]);

        $response->assertRedirect(route('modules.show', $module));

        $module->refresh();

        $this->assertSame('Nom modifié', $module->name);
        $this->assertSame(Module::STATUS_INACTIVE, $module->status);

        $log = AuditLog::query()->where('action', 'module.updated')->sole();

        $this->assertSame('Nom initial', $log->old_values['name']);
        $this->assertSame('Nom modifié', $log->new_values['name']);
        $this->assertSame('Description A', $log->old_values['description']);
        $this->assertSame('Description B', $log->new_values['description']);
        $this->assertSame('0.9.0', $log->old_values['version']);
        $this->assertSame('2.0.0', $log->new_values['version']);
        $this->assertSame(1000, $log->old_values['price']);
        $this->assertSame(2500, $log->new_values['price']);
        $this->assertSame(Module::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(Module::STATUS_INACTIVE, $log->new_values['status']);
        $this->assertSame(1, $log->old_values['sort_order']);
        $this->assertSame(5, $log->new_values['sort_order']);
    }

    public function test_module_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = User::factory()->create();

        $module = Module::query()->create([
            'name' => 'Module à supprimer',
            'slug' => 'delete-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);

        $moduleId = $module->id;

        $response = $this->actingAs($user)->delete(route('modules.destroy', $module));

        $response->assertRedirect(route('modules.index'));

        $this->assertDatabaseMissing('modules', ['id' => $moduleId]);

        $log = AuditLog::query()->where('action', 'module.deleted')->sole();

        $this->assertNull($log->new_values);
        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertSame('Module à supprimer', $log->old_values['name']);
        $this->assertSame($moduleId, $log->old_values['id']);
    }

    public function test_consultation_routes_do_not_create_audit_logs(): void
    {
        $user = User::factory()->create();

        $module = Module::query()->create([
            'name' => 'Consultation',
            'slug' => 'consult-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->get(route('modules.index'))->assertOk();
        $this->actingAs($user)->get(route('modules.show', $module))->assertOk();
        $this->actingAs($user)->get(route('modules.create'))->assertOk();
        $this->actingAs($user)->get(route('modules.edit', $module))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_failed_validation_does_not_create_module_audit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('modules.store'), [
            'name' => '',
            'slug' => '',
        ])->assertSessionHasErrors(['name', 'slug', 'currency', 'status', 'sort_order']);

        $this->assertSame(0, AuditLog::query()->where('action', 'module.created')->count());

        $module = Module::query()->create([
            'name' => 'Module Valide',
            'slug' => 'valid-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);

        AuditLog::query()->delete();

        $this->actingAs($user)->put(route('modules.update', $module), [
            'name' => '',
            'slug' => $module->slug,
        ])->assertSessionHasErrors(['name', 'currency', 'status', 'sort_order']);

        $this->assertSame(0, AuditLog::query()->where('action', 'module.updated')->count());
    }

    public function test_unauthenticated_write_operations_are_rejected_without_audit(): void
    {
        $response = $this->post(route('modules.store'), [
            'name' => 'Sans auth',
            'slug' => 'noauth-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);

        $response->assertRedirect(route('login'));

        $this->assertSame(0, Module::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }
}
