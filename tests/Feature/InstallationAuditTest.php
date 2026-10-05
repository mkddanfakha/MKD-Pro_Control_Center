<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Module;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_creation_is_audited(): void
    {
        $user = $this->controlCenterAdminUser();
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
        $user = $this->controlCenterAdminUser();
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
        $user = $this->controlCenterAdminUser();
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

    public function test_destroy_installation_with_active_subscription_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation avec abonnement actif',
            'subdomain' => 'fk-active-sub-'.uniqid(),
            'status' => 'active',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertInstallationDestroyIsBlockedBySubscriptionsForeignKey($user, $installation, $client);
    }

    public function test_destroy_terminated_installation_with_active_subscription_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation terminée avec abonnement actif',
            'subdomain' => 'fk-term-active-sub-'.uniqid(),
            'status' => 'terminated',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertInstallationDestroyIsBlockedBySubscriptionsForeignKey($user, $installation, $client);
    }

    public function test_destroy_terminated_installation_with_terminated_subscription_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation et abonnement terminés',
            'subdomain' => 'fk-term-term-sub-'.uniqid(),
            'status' => 'terminated',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-09-01 00:00:00',
        ]);

        $this->assertInstallationDestroyIsBlockedBySubscriptionsForeignKey($user, $installation, $client);
    }

    public function test_destroy_installation_with_multiple_subscriptions_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation historique abonnements',
            'subdomain' => 'fk-multi-sub-'.uniqid(),
            'status' => 'active',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 10000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-06-01 00:00:00',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, Subscription::query()->where('installation_id', $installation->id)->count());

        $this->assertInstallationDestroyIsBlockedBySubscriptionsForeignKey($user, $installation, $client);

        $this->assertSame(2, Subscription::query()->where('installation_id', $installation->id)->count());
    }

    public function test_destroy_installation_with_installation_module_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation avec module',
            'subdomain' => 'fk-module-'.uniqid(),
            'status' => 'active',
        ]);

        $module = $this->makeModule();
        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->assertSame(0, Subscription::query()->where('installation_id', $installation->id)->count());

        $this->assertInstallationDestroyIsBlockedByInstallationModulesForeignKey(
            $user,
            $installation,
            $client,
            $module,
            $installationModule,
        );
    }

    public function test_destroy_terminated_installation_with_installation_module_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation terminée avec module',
            'subdomain' => 'fk-term-module-'.uniqid(),
            'status' => 'terminated',
        ]);

        $module = $this->makeModule();
        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->assertInstallationDestroyIsBlockedByInstallationModulesForeignKey(
            $user,
            $installation,
            $client,
            $module,
            $installationModule,
        );
    }

    public function test_destroy_installation_with_subscription_and_installation_module_is_blocked_by_foreign_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation abonnement et module',
            'subdomain' => 'fk-sub-module-'.uniqid(),
            'status' => 'active',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $module = $this->makeModule();
        $installationModule = InstallationModule::query()->create([
            'installation_id' => $installation->id,
            'module_id' => $module->id,
            'status' => InstallationModule::STATUS_ACTIVE,
        ]);

        $this->assertInstallationDestroyIsBlockedWithSubscriptionAndInstallationModule(
            $user,
            $installation,
            $client,
            $module,
            $installationModule,
        );
    }

    public function test_installation_deletion_is_audited_without_polymorphic_reference(): void
    {
        $user = $this->controlCenterAdminUser();
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
        $user = $this->controlCenterAdminUser();
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

    private function assertInstallationDestroyIsBlockedBySubscriptionsForeignKey(
        User $user,
        Installation $installation,
        Client $client,
    ): void {
        $installationId = $installation->id;
        $clientId = $client->id;
        $subscriptionCount = Subscription::query()->where('installation_id', $installationId)->count();

        $this->assertGreaterThan(0, $subscriptionCount);

        $this->actingAs($user)
            ->delete(route('installations.destroy', $installation))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas(
                'error',
                'Cette installation ne peut pas être supprimée car un abonnement lui est encore associé.',
            );

        $this->assertDatabaseHas('installations', ['id' => $installationId]);
        $this->assertDatabaseHas('clients', ['id' => $clientId]);
        $this->assertSame(
            $subscriptionCount,
            Subscription::query()->where('installation_id', $installationId)->count(),
        );
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.deleted')->count());
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

    private function assertInstallationDestroyIsBlockedByInstallationModulesForeignKey(
        User $user,
        Installation $installation,
        Client $client,
        Module $module,
        InstallationModule $installationModule,
    ): void {
        $installationId = $installation->id;
        $clientId = $client->id;
        $moduleId = $module->id;
        $installationModuleId = $installationModule->id;

        $this->actingAs($user)
            ->delete(route('installations.destroy', $installation))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas(
                'error',
                'Cette installation ne peut pas être supprimée car des modules lui sont encore associés.',
            );

        $this->assertDatabaseHas('installations', ['id' => $installationId]);
        $this->assertDatabaseHas('clients', ['id' => $clientId]);
        $this->assertDatabaseHas('modules', ['id' => $moduleId]);
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModuleId]);
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.deleted')->count());
    }

    private function assertInstallationDestroyIsBlockedWithSubscriptionAndInstallationModule(
        User $user,
        Installation $installation,
        Client $client,
        Module $module,
        InstallationModule $installationModule,
    ): void {
        $installationId = $installation->id;

        $this->actingAs($user)
            ->delete(route('installations.destroy', $installation))
            ->assertRedirect(route('installations.show', $installation))
            ->assertSessionHas(
                'error',
                'Cette installation ne peut pas être supprimée car un abonnement lui est encore associé.',
            );

        $this->assertDatabaseHas('installations', ['id' => $installationId]);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertSame(1, Subscription::query()->where('installation_id', $installationId)->count());
        $this->assertDatabaseHas('installation_modules', ['id' => $installationModule->id]);
        $this->assertDatabaseHas('modules', ['id' => $module->id]);
        $this->assertSame(0, AuditLog::query()->where('action', 'installation.deleted')->count());
    }
}
