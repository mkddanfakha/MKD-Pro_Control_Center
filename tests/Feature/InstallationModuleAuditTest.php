<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Module;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationModuleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_module_creation_is_audited(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $payload = [
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
            'version' => '1.0.0',
            'activated_at' => '2026-01-15 10:00:00',
            'deactivated_at' => null,
            'notes' => 'Notes initiales',
        ];

        $response = $this->actingAs($user)->post(route('installation-modules.store'), $payload);

        $response->assertRedirect();

        $installationModule = InstallationModule::query()
            ->where('installation_id', $installation->id)
            ->where('module_id', $module->id)
            ->firstOrFail();

        $log = AuditLog::query()->where('action', 'installation_module.created')->sole();

        $this->assertNull($log->old_values);
        $this->assertNotNull($log->new_values);
        $this->assertSame(InstallationModule::class, $log->auditable_type);
        $this->assertSame($installationModule->id, $log->auditable_id);
        $this->assertSame($installation->id, $log->new_values['installation_id']);
        $this->assertSame($module->id, $log->new_values['module_id']);
        $this->assertSame(InstallationModule::STATUS_ACTIVE, $log->new_values['status']);
        $this->assertSame('1.0.0', $log->new_values['version']);
        $this->assertSame('2026-01-15 10:00:00', $log->new_values['activated_at']);
        $this->assertNull($log->new_values['deactivated_at']);
        $this->assertSame('Notes initiales', $log->new_values['notes']);
    }

    public function test_installation_module_update_is_audited_with_formatted_dates(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
            'version' => '0.9.0',
            'activated_at' => '2026-01-01 08:00:00',
            'deactivated_at' => null,
            'notes' => 'Notes A',
        ]);

        $response = $this->actingAs($user)->put(route('installation-modules.update', $installationModule), [
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_INACTIVE,
            'version' => '2.1.0',
            'activated_at' => '2026-01-01 08:00:00',
            'deactivated_at' => '2026-06-30 18:30:00',
            'notes' => 'Notes B',
        ]);

        $response->assertRedirect(route('installation-modules.show', $installationModule));

        $log = AuditLog::query()->where('action', 'installation_module.updated')->sole();

        $this->assertSame(InstallationModule::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(InstallationModule::STATUS_INACTIVE, $log->new_values['status']);
        $this->assertSame('0.9.0', $log->old_values['version']);
        $this->assertSame('2.1.0', $log->new_values['version']);
        $this->assertSame('2026-01-01 08:00:00', $log->old_values['activated_at']);
        $this->assertSame('2026-01-01 08:00:00', $log->new_values['activated_at']);
        $this->assertNull($log->old_values['deactivated_at']);
        $this->assertSame('2026-06-30 18:30:00', $log->new_values['deactivated_at']);
        $this->assertSame('Notes A', $log->old_values['notes']);
        $this->assertSame('Notes B', $log->new_values['notes']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $log->new_values['deactivated_at']);
    }

    public function test_installation_module_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
            'version' => '1.0.0',
            'notes' => 'À supprimer',
        ]);

        $assignmentId = $installationModule->id;

        $response = $this->actingAs($user)->delete(route('installation-modules.destroy', $installationModule));

        $response->assertRedirect(route('installation-modules.index'));

        $this->assertDatabaseMissing('installation_modules', ['id' => $assignmentId]);

        $log = AuditLog::query()->where('action', 'installation_module.deleted')->sole();

        $this->assertNotNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertSame($assignmentId, $log->old_values['id']);
        $this->assertSame('À supprimer', $log->old_values['notes']);
    }

    public function test_deleting_last_installation_module_allows_installation_deletion(): void
    {
        $user = User::factory()->create();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation sans abonnement',
            'subdomain' => 'last-pivot-inst-'.uniqid(),
            'status' => 'active',
        ]);

        $module = $this->makeModule();

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $installationId = $installation->id;
        $assignmentId = $installationModule->id;

        $this->actingAs($user)
            ->delete(route('installation-modules.destroy', $installationModule))
            ->assertRedirect(route('installation-modules.index'));

        $this->assertDatabaseMissing('installation_modules', ['id' => $assignmentId]);
        $this->assertDatabaseHas('installations', ['id' => $installationId]);
        $this->assertDatabaseHas('modules', ['id' => $module->id]);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);

        $this->assertSame(1, AuditLog::query()->where('action', 'installation_module.deleted')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.deleted')->count());

        $this->actingAs($user)
            ->delete(route('installations.destroy', $installation))
            ->assertRedirect(route('installations.index'));

        $this->assertDatabaseMissing('installations', ['id' => $installationId]);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('modules', ['id' => $module->id]);

        $this->assertSame(1, AuditLog::query()->where('action', 'installation.deleted')->count());
    }

    public function test_deleting_last_installation_module_allows_module_deletion(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $moduleId = $module->id;
        $installationId = $installation->id;
        $assignmentId = $installationModule->id;

        $this->actingAs($user)
            ->delete(route('installation-modules.destroy', $installationModule))
            ->assertRedirect(route('installation-modules.index'));

        $this->assertDatabaseMissing('installation_modules', ['id' => $assignmentId]);
        $this->assertDatabaseHas('modules', ['id' => $moduleId]);
        $this->assertDatabaseHas('installations', ['id' => $installationId]);

        $this->assertSame(1, AuditLog::query()->where('action', 'installation_module.deleted')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'module.deleted')->count());

        $this->actingAs($user)
            ->delete(route('modules.destroy', $module))
            ->assertRedirect(route('modules.index'));

        $this->assertDatabaseMissing('modules', ['id' => $moduleId]);
        $this->assertDatabaseHas('installations', ['id' => $installationId]);

        $this->assertSame(1, AuditLog::query()->where('action', 'module.deleted')->count());
    }

    public function test_deleting_one_installation_module_keeps_other_module_assignments(): void
    {
        $user = User::factory()->create();
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();
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

        $this->actingAs($user)
            ->delete(route('installation-modules.destroy', $installationModuleA))
            ->assertRedirect(route('installation-modules.index'));

        $this->assertDatabaseMissing('installation_modules', ['id' => $installationModuleA->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleB->id]);
        $this->assertDatabaseHas('installations', ['id' => $installationA->id]);
        $this->assertDatabaseHas('installations', ['id' => $installationB->id]);
        $this->assertDatabaseHas('modules', ['id' => $module->id]);

        $this->assertSame(1, AuditLog::query()->where('action', 'installation_module.deleted')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'module.deleted')->count());

        $this->assertModuleDestroyIsBlockedByForeignKey($user, $module);

        $this->assertDatabaseHas('modules', ['id' => $module->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleB->id]);
    }

    public function test_deleting_one_installation_module_keeps_other_installation_assignments(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $moduleM1 = $this->makeModule();
        $moduleM2 = Module::query()->create([
            'name' => 'Module M2',
            'slug' => 'module-m2-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 1,
        ]);

        $installationModuleM1 = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $moduleM1->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $installationModuleM2 = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $moduleM2->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->delete(route('installation-modules.destroy', $installationModuleM1))
            ->assertRedirect(route('installation-modules.index'));

        $this->assertDatabaseMissing('installation_modules', ['id' => $installationModuleM1->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleM2->id]);
        $this->assertDatabaseHas('installations', ['id' => $installation->id]);
        $this->assertDatabaseHas('modules', ['id' => $moduleM1->id]);
        $this->assertDatabaseHas('modules', ['id' => $moduleM2->id]);

        $this->assertSame(1, AuditLog::query()->where('action', 'installation_module.deleted')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.deleted')->count());

        $this->assertInstallationDestroyIsBlockedByForeignKey($user, $installation);

        $this->assertDatabaseHas('installations', ['id' => $installation->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleM2->id]);
    }

    public function test_deleting_inactive_installation_module_keeps_parents_unchanged(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $installationStatusBefore = $installation->status;
        $moduleStatusBefore = $module->status;

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_INACTIVE,
        ]);

        $assignmentId = $installationModule->id;

        $this->actingAs($user)
            ->delete(route('installation-modules.destroy', $installationModule))
            ->assertRedirect(route('installation-modules.index'));

        $this->assertDatabaseMissing('installation_modules', ['id' => $assignmentId]);
        $this->assertDatabaseHas('installations', [
            'id' => $installation->id,
            'status' => $installationStatusBefore,
        ]);
        $this->assertDatabaseHas('modules', [
            'id' => $module->id,
            'status' => $moduleStatusBefore,
        ]);

        $log = AuditLog::query()->where('action', 'installation_module.deleted')->sole();

        $this->assertSame($assignmentId, $log->old_values['id']);
        $this->assertSame(InstallationModule::STATUS_INACTIVE, $log->old_values['status']);
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.deleted')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'module.deleted')->count());
    }

    public function test_consultation_routes_do_not_create_audit_logs(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->get(route('installation-modules.index'))->assertOk();
        $this->actingAs($user)->get(route('installation-modules.show', $installationModule))->assertOk();
        $this->actingAs($user)->get(route('installation-modules.create'))->assertOk();
        $this->actingAs($user)->get(route('installation-modules.edit', $installationModule))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_duplicate_installation_module_pair_records_only_one_creation_audit(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $payload = [
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ];

        $this->actingAs($user)->post(route('installation-modules.store'), $payload)->assertRedirect();

        $this->actingAs($user)->post(route('installation-modules.store'), $payload)
            ->assertSessionHasErrors(['module_id']);

        $this->assertSame(1, InstallationModule::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'installation_module.created')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'module.created')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.updated')->count());
    }

    public function test_failed_validation_does_not_create_installation_module_audit(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $this->actingAs($user)->post(route('installation-modules.store'), [
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => 'invalid-status',
        ])->assertSessionHasErrors(['status']);

        $this->assertSame(0, AuditLog::query()->where('action', 'installation_module.created')->count());

        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        AuditLog::query()->delete();

        $this->actingAs($user)->put(route('installation-modules.update', $installationModule), [
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => 'invalid-status',
        ])->assertSessionHasErrors(['status']);

        $this->assertSame(0, AuditLog::query()->where('action', 'installation_module.updated')->count());
    }

    public function test_unauthenticated_write_operations_are_rejected_without_audit(): void
    {
        $installation = $this->makeInstallation();
        $module = $this->makeModule();

        $response = $this->post(route('installation-modules.store'), [
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $response->assertRedirect(route('login'));

        $this->assertSame(0, InstallationModule::query()->count());
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
        $client = $this->makeClient();

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'inst-'.uniqid(),
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
        $moduleDeletedAuditCount = AuditLog::query()->where('action', 'module.deleted')->count();

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->delete(route('modules.destroy', $module));
            $this->fail('Expected module deletion to be blocked by installation_modules foreign key (QueryException).');
        } catch (QueryException $exception) {
            $this->assertNotSame('', trim($exception->getMessage()));
        }

        $this->assertSame(
            $moduleDeletedAuditCount,
            AuditLog::query()->where('action', 'module.deleted')->count(),
        );
    }

    private function assertInstallationDestroyIsBlockedByForeignKey(User $user, Installation $installation): void
    {
        $installationDeletedAuditCount = AuditLog::query()->where('action', 'installation.deleted')->count();

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->delete(route('installations.destroy', $installation));
            $this->fail('Expected installation deletion to be blocked by installation_modules foreign key (QueryException).');
        } catch (QueryException $exception) {
            $this->assertNotSame('', trim($exception->getMessage()));
        }

        $this->assertSame(
            $installationDeletedAuditCount,
            AuditLog::query()->where('action', 'installation.deleted')->count(),
        );
    }
}
