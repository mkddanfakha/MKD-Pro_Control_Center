<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_creation_is_audited(): void
    {
        $user = $this->controlCenterAdminUser();

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
        $user = $this->controlCenterAdminUser();

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
        $user = $this->controlCenterAdminUser();

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

    public function test_destroy_module_with_installation_module_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation avec module catalogue',
            'subdomain' => 'fk-mod-'.uniqid(),
            'status' => 'active',
        ]);

        $module = $this->makeModule();

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->assertModuleDestroyIsBlockedByForeignKey($user, $module);

        $this->assertDatabaseHas('modules', ['id' => $module->id]);
        $this->assertDatabaseHas('installations', ['id' => $installation->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModule->id]);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_destroy_module_with_multiple_installation_modules_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installationA = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation A',
            'subdomain' => 'fk-mod-a-'.uniqid(),
            'status' => 'active',
        ]);

        $installationB = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation B',
            'subdomain' => 'fk-mod-b-'.uniqid(),
            'status' => 'active',
        ]);

        $module = $this->makeModule();

        $installationModuleA = InstallationModule::query()->create([
            'installation_id' => $installationA->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $installationModuleB = InstallationModule::query()->create([
            'installation_id' => $installationB->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->assertModuleDestroyIsBlockedByForeignKey($user, $module);

        $this->assertDatabaseHas('modules', ['id' => $module->id]);
        $this->assertDatabaseHas('installations', ['id' => $installationA->id]);
        $this->assertDatabaseHas('installations', ['id' => $installationB->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleA->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleB->id]);
    }

    public function test_destroy_inactive_module_with_installation_module_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $module = Module::query()->create([
            'name' => 'Module inactif',
            'slug' => 'inactive-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_INACTIVE,
            'sort_order' => 0,
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation module inactif',
            'subdomain' => 'fk-inact-mod-'.uniqid(),
            'status' => 'active',
        ]);

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->assertModuleDestroyIsBlockedByForeignKey($user, $module);

        $this->assertDatabaseHas('modules', [
            'id' => $module->id,
            'status' => Module::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('installations', ['id' => $installation->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModule->id]);
    }

    public function test_consultation_routes_do_not_create_audit_logs(): void
    {
        $user = $this->controlCenterAdminUser();

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
        $user = $this->controlCenterAdminUser();

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

    private function makeClient(): Client
    {
        return Client::query()->create([
            'company_name' => 'Client Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);
    }

    private function makeModule(): Module
    {
        return Module::query()->create([
            'name' => 'Module Test',
            'slug' => 'module-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ]);
    }

    private function assertModuleDestroyIsBlockedByForeignKey(User $user, Module $module): void
    {
        $this->actingAs($user)
            ->delete(route('modules.destroy', $module))
            ->assertRedirect(route('modules.show', $module))
            ->assertSessionHas(
                'error',
                'Ce module ne peut pas être supprimé car il est encore affecté à une ou plusieurs installations.',
            );

        $this->assertSame(0, AuditLog::query()->where('action', 'module.deleted')->count());
    }
}
