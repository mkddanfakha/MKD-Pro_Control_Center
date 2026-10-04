<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ControlCenterAccessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'admin@control-center.test';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('control_center.admin_email', self::ADMIN_EMAIL);
    }

    public function test_gate_denies_guest(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('accessControlCenter'));
    }

    public function test_gate_denies_when_admin_email_not_configured(): void
    {
        Config::set('control_center.admin_email', null);

        $user = User::factory()->create(['email' => self::ADMIN_EMAIL]);

        $this->assertFalse(Gate::forUser($user)->allows('accessControlCenter'));
    }

    public function test_gate_denies_when_admin_email_is_empty_string(): void
    {
        Config::set('control_center.admin_email', '   ');

        $user = User::factory()->create(['email' => self::ADMIN_EMAIL]);

        $this->assertFalse(Gate::forUser($user)->allows('accessControlCenter'));
    }

    public function test_gate_allows_configured_admin_email_case_and_whitespace_insensitive(): void
    {
        Config::set('control_center.admin_email', '  Admin@Control-Center.TEST  ');

        $user = User::factory()->create(['email' => 'admin@control-center.test']);

        $this->assertTrue(Gate::forUser($user)->allows('accessControlCenter'));
    }

    public function test_gate_denies_other_authenticated_user(): void
    {
        $user = $this->nonControlCenterUser();

        $this->assertFalse(Gate::forUser($user)->allows('accessControlCenter'));
    }

    public function test_gate_denies_default_factory_user_without_control_center_admin_state(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('accessControlCenter'));
    }

    public function test_gate_allows_control_center_admin_user(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->assertTrue(Gate::forUser($user)->allows('accessControlCenter'));
    }

    public function test_guest_is_redirected_to_login_for_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_non_admin_authenticated_user_is_forbidden_on_dashboard(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_admin_authenticated_user_can_open_dashboard(): void
    {
        $this->actingAs($this->controlCenterAdminUser())
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_non_admin_cannot_access_clients_index(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->get(route('clients.index'))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_post_clients_store(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->post(route('clients.store'), [
                'company_name' => 'Société',
                'contact_name' => 'Contact',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_access_installations_index(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->get(route('installations.index'))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_access_commercial_products_index(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->get(route('commercial.products.index'))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_post_commercial_products_store(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->post(route('commercial.products.store'), [
                'code' => 'X',
                'name' => 'Produit',
                'status' => Product::STATUS_ACTIVE,
            ])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_post_subscriptions_store(): void
    {
        $client = Client::query()->create([
            'company_name' => 'C',
            'contact_name' => 'N',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'I',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($this->nonControlCenterUser())
            ->post(route('subscriptions.store'), [
                'installation_id' => $installation->id,
                'status' => 'active',
                'current_period_start' => '2026-01-01',
                'current_period_end' => '2026-12-31',
            ])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_post_payments_store(): void
    {
        $this->actingAs($this->nonControlCenterUser())
            ->post(route('payments.store'), [
                'subscription_id' => 1,
                'amount' => 1000,
                'currency' => 'XOF',
                'paid_at' => '2026-06-01',
                'method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_elevate_via_profile_update_when_admin_email_is_unassigned(): void
    {
        $other = User::factory()->create(['email' => 'ordinary@example.test']);

        $this->actingAs($other)
            ->put(route('user-profile-information.update'), [
                'name' => $other->name,
                'email' => self::ADMIN_EMAIL,
            ])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $other->refresh();

        $this->assertSame('ordinary@example.test', strtolower(trim($other->email)));
        $this->assertFalse(Gate::forUser($other)->allows('accessControlCenter'));
        $this->assertSame(0, User::query()->whereRaw('LOWER(TRIM(email)) = ?', [self::ADMIN_EMAIL])->count());
    }

    public function test_non_admin_cannot_take_admin_email_via_profile_update_when_already_assigned(): void
    {
        $this->controlCenterAdminUser();
        $other = $this->nonControlCenterUser();

        $this->actingAs($other)
            ->put(route('user-profile-information.update'), [
                'name' => $other->name,
                'email' => self::ADMIN_EMAIL,
            ])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $other->refresh();

        $this->assertSame('other-user@example.test', strtolower(trim($other->email)));
        $this->assertFalse(Gate::forUser($other)->allows('accessControlCenter'));
        $this->assertSame(1, User::query()->whereRaw('LOWER(TRIM(email)) = ?', [self::ADMIN_EMAIL])->count());
    }

    public function test_control_center_admin_remains_authorized_and_cannot_change_admin_email(): void
    {
        $admin = User::factory()->controlCenterAdmin()->create([
            'name' => 'Admin CC',
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('accessControlCenter'));

        $this->actingAs($admin)
            ->put(route('user-profile-information.update'), [
                'name' => 'Admin CC',
                'email' => 'other-email@example.test',
            ])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $admin->refresh();

        $this->assertSame(self::ADMIN_EMAIL, strtolower(trim($admin->email)));
        $this->assertTrue(Gate::forUser($admin)->allows('accessControlCenter'));

        $this->actingAs($admin)
            ->put(route('user-profile-information.update'), [
                'name' => 'Admin CC renommé',
                'email' => self::ADMIN_EMAIL,
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();

        $this->assertSame('Admin CC renommé', $admin->name);
        $this->assertTrue(Gate::forUser($admin)->allows('accessControlCenter'));
    }

    public function test_create_admin_user_command_fails_when_control_center_admin_email_is_not_configured(): void
    {
        Config::set('control_center.admin_email', null);

        $this->artisan('app:create-admin-user')
            ->expectsOutput('CONTROL_CENTER_ADMIN_EMAIL est absent ou vide. Configurez l’adresse administrateur avant de créer le compte.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_create_admin_user_command_uses_configured_admin_email(): void
    {
        $this->artisan('app:create-admin-user')
            ->expectsQuestion('Nom de l’administrateur', 'Admin Config')
            ->expectsQuestion('Mot de passe', 'cli-test-pass-8')
            ->expectsQuestion('Confirmer le mot de passe', 'cli-test-pass-8')
            ->assertSuccessful();

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [self::ADMIN_EMAIL])->first();

        $this->assertNotNull($user);
        $this->assertSame('Admin Config', $user->name);
        $this->assertTrue(Gate::forUser($user)->allows('accessControlCenter'));
        $this->assertSame(1, User::query()->count());
    }

    public function test_create_admin_user_command_fails_when_admin_account_already_exists(): void
    {
        User::factory()->controlCenterAdmin()->create();

        $this->artisan('app:create-admin-user')
            ->expectsOutput('Un utilisateur existe déjà avec l’adresse e-mail administrateur configurée.')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_no_public_register_route_exists(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('register'));
        $this->assertEmpty(collect(Route::getRoutes())->filter(
            fn ($route) => in_array('register', $route->gatherMiddleware(), true)
                || str_contains($route->uri(), 'register')
        )->filter(fn ($route) => in_array('POST', $route->methods(), true)));
    }

    public function test_commercial_destroy_routes_are_not_registered(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('commercial.products.destroy'));
        $this->assertNull(Route::getRoutes()->getByName('commercial.offers.destroy'));
        $this->assertNull(Route::getRoutes()->getByName('commercial.offer-versions.destroy'));
    }

    public function test_commercial_publish_and_retire_routes_exist_with_access_control_center(): void
    {
        foreach (['commercial.offer-versions.publish', 'commercial.offer-versions.retire'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('can:accessControlCenter', $route->middleware(), $name);
        }
    }

    public function test_commercial_product_routes_use_access_control_center_middleware(): void
    {
        foreach (['commercial.products.index', 'commercial.products.store'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('can:accessControlCenter', $route->middleware(), $name);
        }
    }

    public function test_subscription_mutation_routes_use_access_control_center_middleware(): void
    {
        foreach (['subscriptions.create', 'subscriptions.store', 'subscriptions.update', 'subscriptions.destroy'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('can:accessControlCenter', $route->middleware(), $name);
        }
    }

    public function test_payment_mutation_routes_use_access_control_center_middleware(): void
    {
        foreach (['payments.create', 'payments.store', 'payments.update', 'payments.destroy'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('can:accessControlCenter', $route->middleware(), $name);
        }
    }
}
