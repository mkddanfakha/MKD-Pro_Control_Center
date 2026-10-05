<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Durcissement modules + suppression — TASK 332.
 */
class ModuleAdminSecurityAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function denyControlCenter(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);
    }

    private function validModulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Module Test',
            'slug' => 'mod-'.uniqid(),
            'currency' => 'XOF',
            'status' => Module::STATUS_ACTIVE,
            'sort_order' => 0,
        ], $overrides);
    }

    private function validInstallationModulePayload(Installation $installation, Module $module, array $overrides = []): array
    {
        return array_merge([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ], $overrides);
    }

    // --- Invité modules (1–7) ---

    public function test_guest_cannot_access_modules_index(): void
    {
        $this->get(route('modules.index'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_modules_create(): void
    {
        $this->get(route('modules.create'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_post_modules_store(): void
    {
        $this->post(route('modules.store'), $this->validModulePayload())->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_modules_show(): void
    {
        $module = Module::query()->create($this->validModulePayload());

        $this->get(route('modules.show', $module))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_modules_edit(): void
    {
        $module = Module::query()->create($this->validModulePayload());

        $this->get(route('modules.edit', $module))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_put_modules_update(): void
    {
        $module = Module::query()->create($this->validModulePayload());

        $this->put(route('modules.update', $module), $this->validModulePayload([
            'slug' => $module->slug,
            'name' => 'X',
        ]))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_delete_module(): void
    {
        $module = Module::query()->create($this->validModulePayload());

        $this->delete(route('modules.destroy', $module))->assertRedirect(route('login'));
    }

    // --- Invité installation-modules (8–14) ---

    public function test_guest_cannot_access_installation_modules_index(): void
    {
        $this->get(route('installation-modules.index'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_installation_modules_create(): void
    {
        $this->get(route('installation-modules.create'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_post_installation_modules_store(): void
    {
        $installation = $this->makeInstallation();
        $module = Module::query()->create($this->validModulePayload());

        $this->post(route('installation-modules.store'), $this->validInstallationModulePayload($installation, $module))
            ->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_installation_modules_show(): void
    {
        $link = $this->makeInstallationModuleLink();

        $this->get(route('installation-modules.show', $link))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_installation_modules_edit(): void
    {
        $link = $this->makeInstallationModuleLink();

        $this->get(route('installation-modules.edit', $link))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_put_installation_modules_update(): void
    {
        $link = $this->makeInstallationModuleLink();
        $link->load(['installation', 'module']);

        $this->put(route('installation-modules.update', $link), $this->validInstallationModulePayload(
            $link->installation,
            $link->module,
            ['status' => InstallationModule::STATUS_INACTIVE],
        ))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_delete_installation_module(): void
    {
        $link = $this->makeInstallationModuleLink();

        $this->delete(route('installation-modules.destroy', $link))->assertRedirect(route('login'));
    }

    // --- Non autorisé ---

    public function test_unauthorized_user_cannot_mutate_modules(): void
    {
        $this->denyControlCenter();
        $user = User::factory()->create();
        $module = Module::query()->create($this->validModulePayload());

        $this->actingAs($user)->get(route('modules.create'))->assertForbidden();
        $this->actingAs($user)->post(route('modules.store'), $this->validModulePayload([
            'slug' => 'blocked-'.uniqid(),
        ]))->assertForbidden();
        $this->actingAs($user)->get(route('modules.edit', $module))->assertForbidden();
        $this->actingAs($user)->put(route('modules.update', $module), $this->validModulePayload([
            'slug' => $module->slug,
        ]))->assertForbidden();
        $this->actingAs($user)->delete(route('modules.destroy', $module))->assertForbidden();
    }

    public function test_unauthorized_user_cannot_mutate_installation_modules(): void
    {
        $this->denyControlCenter();
        $user = User::factory()->create();
        $link = $this->makeInstallationModuleLink();
        $link->load(['installation', 'module']);

        $this->actingAs($user)->get(route('installation-modules.create'))->assertForbidden();
        $this->actingAs($user)->post(route('installation-modules.store'), $this->validInstallationModulePayload(
            $link->installation,
            $link->module,
        ))->assertForbidden();
        $this->actingAs($user)->get(route('installation-modules.edit', $link))->assertForbidden();
        $this->actingAs($user)->put(route('installation-modules.update', $link), $this->validInstallationModulePayload(
            $link->installation,
            $link->module,
        ))->assertForbidden();
        $this->actingAs($user)->delete(route('installation-modules.destroy', $link))->assertForbidden();
    }

    // --- Autorisé ---

    public function test_authorized_user_can_access_module_pages_and_mutations(): void
    {
        $user = $this->controlCenterAdminUser();
        $module = Module::query()->create($this->validModulePayload());

        $this->actingAs($user)->get(route('modules.index'))->assertOk();
        $this->actingAs($user)->get(route('modules.create'))->assertOk();
        $this->actingAs($user)->get(route('modules.show', $module))->assertOk();
        $this->actingAs($user)->get(route('modules.edit', $module))->assertOk();

        $this->actingAs($user)->put(route('modules.update', $module), $this->validModulePayload([
            'slug' => $module->slug,
            'name' => 'Module MAJ TASK332',
        ]))->assertRedirect(route('modules.show', $module));
    }

    public function test_authorized_user_can_access_installation_module_mutations(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $module = Module::query()->create($this->validModulePayload());

        $this->actingAs($user)
            ->post(route('installation-modules.store'), $this->validInstallationModulePayload($installation, $module))
            ->assertRedirect();

        $link = InstallationModule::query()->where('installation_id', $installation->id)->firstOrFail();

        $this->actingAs($user)->get(route('installation-modules.show', $link))->assertOk();
        $this->actingAs($user)->get(route('installation-modules.edit', $link))->assertOk();
    }

    // --- Suppression module ---

    public function test_module_without_assignments_can_be_deleted_with_audit(): void
    {
        $user = $this->controlCenterAdminUser();
        $module = Module::query()->create($this->validModulePayload());
        $moduleId = $module->id;

        $this->actingAs($user)
            ->delete(route('modules.destroy', $module))
            ->assertRedirect(route('modules.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('modules', ['id' => $moduleId]);
        $this->assertSame(1, AuditLog::query()->where('action', 'module.deleted')->count());
    }

    public function test_module_with_assignment_cannot_be_deleted_and_preserves_data(): void
    {
        $user = $this->controlCenterAdminUser();
        $module = Module::query()->create($this->validModulePayload());
        $link = InstallationModule::query()->create([
            'installation_id' => $this->makeInstallation()->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->delete(route('modules.destroy', $module))
            ->assertRedirect(route('modules.show', $module))
            ->assertSessionHas(
                'error',
                'Ce module ne peut pas être supprimé car il est encore affecté à une ou plusieurs installations.',
            );

        $this->assertDatabaseHas('modules', ['id' => $module->id]);
        $this->assertDatabaseHas('installation_modules', ['id' => $link->id]);
        $this->assertSame(0, AuditLog::query()->where('action', 'module.deleted')->count());
    }

    // --- Installation Show ---

    public function test_installation_show_exposes_assigned_modules_in_props(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $module = Module::query()->create($this->validModulePayload([
            'name' => 'Module Show TASK332',
            'price' => 5000,
        ]));

        InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
            'version' => '2.1.0',
            'activated_at' => '2026-09-15 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installation_modules', 1)
                ->where('installation_modules.0.status', InstallationModule::STATUS_ACTIVE)
                ->where('installation_modules.0.version', '2.1.0')
                ->where('installation_modules.0.module.name', 'Module Show TASK332')
                ->where('installation_modules.0.module.price', 5000)
                ->has('installation_modules.0.activated_at')
                ->has('administrative_readiness.checklist'));
    }

    public function test_installation_show_readiness_documents_technical_deployment_not_tracked(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('administrative_readiness.technical_deployment.tracked', false)
                ->where('administrative_readiness.control_center_registration.value', 'Enregistré'));
    }

    public function test_module_routes_use_access_control_center_middleware(): void
    {
        foreach ([
            'modules.index',
            'modules.store',
            'installation-modules.index',
            'installation-modules.store',
        ] as $routeName) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === $routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains('can:accessControlCenter', $route->middleware(), $routeName);
        }
    }

    private function makeInstallation(): Installation
    {
        return Installation::query()->create([
            'client_id' => Client::query()->create([
                'company_name' => 'Client Modules',
                'contact_name' => 'Contact',
                'status' => 'active',
            ])->id,
            'name' => 'Installation Modules',
            'subdomain' => 'mod-'.uniqid(),
            'status' => 'active',
        ]);
    }

    private function makeInstallationModuleLink(): InstallationModule
    {
        $installation = $this->makeInstallation();
        $module = Module::query()->create($this->validModulePayload());

        return InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);
    }
}
