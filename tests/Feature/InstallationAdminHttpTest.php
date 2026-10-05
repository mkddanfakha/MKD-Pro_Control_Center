<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_cannot_access_installations_index(): void
    {
        $this->get(route('installations.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_control_center_access_is_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertForbidden();
    }

    public function test_authenticated_control_center_user_can_view_installations_index(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Central Alpha']);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Index')
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $installation->id)
                ->has('clients'));
    }

    public function test_index_lists_installations_ordered_by_created_at_desc(): void
    {
        $user = $this->controlCenterAdminUser();
        $older = $this->makeInstallation(['name' => 'Older']);
        $newer = $this->makeInstallation(['name' => 'Newer']);
        $older->forceFill(['created_at' => '2026-01-01 10:00:00'])->saveQuietly();
        $newer->forceFill(['created_at' => '2026-06-01 10:00:00'])->saveQuietly();

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.id', $newer->id)
                ->where('installations.data.1.id', $older->id));
    }

    public function test_index_supports_pagination_with_query_string(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($index = 0; $index < 16; $index++) {
            $this->makeInstallation(['name' => 'Install '.$index]);
        }

        $response = $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'active']))
            ->assertOk();

        $pageData = $response->viewData('page');
        $links = $pageData['props']['installations']['links'] ?? [];
        $pageTwo = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwo);
        $this->assertStringContainsString('status=active', (string) $pageTwo['url']);
    }

    public function test_index_filters_by_status(): void
    {
        $user = $this->controlCenterAdminUser();
        $active = $this->makeInstallation(['status' => 'active']);
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $active->id)
                ->where('filters.status', 'active'));
    }

    public function test_index_filters_by_client(): void
    {
        $user = $this->controlCenterAdminUser();
        $clientA = Client::query()->create([
            'company_name' => 'Client A',
            'contact_name' => 'Contact A',
            'status' => 'active',
        ]);
        $clientB = Client::query()->create([
            'company_name' => 'Client B',
            'contact_name' => 'Contact B',
            'status' => 'active',
        ]);

        $match = Installation::query()->create([
            'client_id' => $clientA->id,
            'name' => 'Install A',
            'subdomain' => 'a-'.uniqid(),
            'status' => 'active',
        ]);
        Installation::query()->create([
            'client_id' => $clientB->id,
            'name' => 'Install B',
            'subdomain' => 'b-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('installations.index', ['client_id' => $clientA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $match->id)
                ->where('filters.client_id', $clientA->id));
    }

    public function test_index_search_by_name(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeInstallation(['name' => 'Boutique Dakar']);
        $this->makeInstallation(['name' => 'Autre site']);

        $this->actingAs($user)
            ->get(route('installations.index', ['search' => 'Dakar']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $match->id));
    }

    public function test_index_search_by_subdomain(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeInstallation(['subdomain' => 'unique-sub-294']);
        $this->makeInstallation(['subdomain' => 'other-sub']);

        $this->actingAs($user)
            ->get(route('installations.index', ['search' => 'unique-sub-294']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $match->id));
    }

    public function test_index_search_by_domain(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeInstallation(['domain' => 'app.example.test']);
        $this->makeInstallation(['domain' => 'other.example.test']);

        $this->actingAs($user)
            ->get(route('installations.index', ['search' => 'app.example']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $match->id));
    }

    public function test_index_filters_by_version(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeInstallation(['version' => '2.4.1']);
        $this->makeInstallation(['version' => '1.0.0']);

        $this->actingAs($user)
            ->get(route('installations.index', ['version' => '2.4.1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $match->id)
                ->where('filters.version', '2.4.1'));
    }

    public function test_index_exposes_current_subscription_for_non_terminated_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-12-31 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.current_subscription.status', Subscription::STATUS_ACTIVE)
                ->where('installations.data.0.current_subscription.current_period_end', '2026-12-31 23:59:59'));
    }

    public function test_index_reports_no_active_subscription_when_only_terminated_exists(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.current_subscription', null));
    }

    public function test_index_installation_status_active(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['status' => 'active']);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.status', 'active'));
    }

    public function test_index_installation_status_inactive(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeInstallation(['status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.status', 'inactive'));
    }

    public function test_index_installation_status_suspended(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeInstallation(['status' => 'suspended']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.status', 'suspended'));
    }

    public function test_index_installation_status_terminated(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'terminated']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.status', 'terminated'));
    }

    public function test_index_does_not_expose_sensitive_database_fields(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeInstallation([
            'database_name' => 'secret_db',
            'database_host' => '10.0.0.99',
        ]);

        $response = $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk();

        $json = json_encode($response->viewData('page')['props'] ?? []);

        $this->assertIsString($json);
        $this->assertStringNotContainsString('secret_db', $json);
        $this->assertStringNotContainsString('10.0.0.99', $json);
        $this->assertStringNotContainsString('database_name', $json);
        $this->assertStringNotContainsString('database_host', $json);
    }

    public function test_index_get_does_not_mutate_installations(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Stable Name']);

        $before = $installation->fresh()->only(['name', 'status', 'updated_at']);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk();

        $after = $installation->fresh()->only(['name', 'status', 'updated_at']);

        $this->assertSame($before['name'], $after['name']);
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame(
            $before['updated_at']?->format('Y-m-d H:i:s'),
            $after['updated_at']?->format('Y-m-d H:i:s'),
        );
    }

    public function test_index_does_not_send_email(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-01 00:00:00',
            'detected_at' => '2026-10-01 08:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk();

        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstallation(array $attributes = []): Installation
    {
        $clientId = $attributes['client_id'] ?? Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ])->id;
        unset($attributes['client_id']);

        return Installation::query()->create(array_merge([
            'client_id' => $clientId,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ], $attributes));
    }
}
