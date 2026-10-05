<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('clients.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_view_clients_index(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeClient(['company_name' => 'Alpha Corp']);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 1)
                ->where('clients.data.0.company_name', 'Alpha Corp'));
    }

    public function test_index_supports_pagination_with_query_string(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($index = 0; $index < 16; $index++) {
            $this->makeClient(['company_name' => 'Client '.$index]);
        }

        $response = $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'active']))
            ->assertOk();

        $links = $response->viewData('page')['props']['clients']['links'] ?? [];
        $pageTwo = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwo);
        $this->assertStringContainsString('status=active', (string) $pageTwo['url']);
    }

    public function test_search_by_company_name(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeClient(['company_name' => 'Boutique Dakar']);
        $this->makeClient(['company_name' => 'Autre Entreprise']);

        $this->actingAs($user)
            ->get(route('clients.index', ['search' => 'Dakar']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $match->id));
    }

    public function test_search_by_email(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeClient(['email' => 'contact@unique-client.test']);
        $this->makeClient(['email' => 'other@example.test']);

        $this->actingAs($user)
            ->get(route('clients.index', ['search' => 'unique-client']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $match->id));
    }

    public function test_filter_by_email_field(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeClient(['email' => 'filtre@example.test']);
        $this->makeClient(['email' => 'autre@example.test']);

        $this->actingAs($user)
            ->get(route('clients.index', ['email' => 'filtre@example']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $match->id)
                ->where('filters.email', 'filtre@example'));
    }

    public function test_search_by_phone(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makeClient(['phone' => '+221771234567']);
        $this->makeClient(['phone' => '+221779999999']);

        $this->actingAs($user)
            ->get(route('clients.index', ['search' => '771234567']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $match->id));
    }

    public function test_search_by_installation_name(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Horizon Unique',
            'subdomain' => 'horizon-'.uniqid(),
            'status' => 'active',
        ]);
        $this->makeClient();

        $this->actingAs($user)
            ->get(route('clients.index', ['search' => 'Horizon Unique']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $client->id));
    }

    public function test_installation_statistics_are_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Active',
            'subdomain' => 'a-'.uniqid(),
            'status' => 'active',
        ]);
        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Suspended',
            'subdomain' => 's-'.uniqid(),
            'status' => 'suspended',
        ]);
        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Terminated',
            'subdomain' => 't-'.uniqid(),
            'status' => 'terminated',
        ]);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.data.0.installations_count', 3)
                ->where('clients.data.0.installations_active_count', 1)
                ->where('clients.data.0.installations_suspended_count', 1)
                ->where('clients.data.0.installations_terminated_count', 1));
    }

    public function test_non_terminated_subscriptions_count_is_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Site',
            'subdomain' => 'site-'.uniqid(),
            'status' => 'active',
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.data.0.non_terminated_subscriptions_count', 1));
    }

    public function test_client_without_installations_shows_zero_counts(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeClient();

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.data.0.installations_count', 0)
                ->where('clients.data.0.installations_active_count', 0)
                ->where('clients.data.0.non_terminated_subscriptions_count', 0));
    }

    public function test_multiple_clients_with_installations_are_listed(): void
    {
        $user = $this->controlCenterAdminUser();
        $first = $this->makeClient(['company_name' => 'Client A']);
        $second = $this->makeClient(['company_name' => 'Client B']);

        Installation::query()->create([
            'client_id' => $first->id,
            'name' => 'Inst A',
            'subdomain' => 'a-'.uniqid(),
            'status' => 'active',
        ]);
        Installation::query()->create([
            'client_id' => $second->id,
            'name' => 'Inst B',
            'subdomain' => 'b-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('clients.data', 2));
    }

    public function test_sensitive_fields_are_not_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Sec',
            'subdomain' => 'sec-'.uniqid(),
            'status' => 'active',
            'database_name' => 'secret_db',
            'database_host' => '10.0.0.5',
        ]);

        $response = $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk();

        $clientsJson = json_encode($response->viewData('page')['props']['clients']['data'] ?? []);

        $this->assertIsString($clientsJson);
        foreach (['database_name', 'database_host', 'secret_db', '10.0.0.5', 'password', 'credentials'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clientsJson);
        }
    }

    public function test_get_does_not_mutate_clients(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient(['company_name' => 'Stable Name']);

        $before = $client->fresh()->only(['company_name', 'status']);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk();

        $this->assertSame($before, $client->fresh()->only(['company_name', 'status']));
    }

    public function test_index_does_not_send_email(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makeClient();

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_index_does_not_trigger_obvious_n_plus_one(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($index = 0; $index < 5; $index++) {
            $client = $this->makeClient();
            Installation::query()->create([
                'client_id' => $client->id,
                'name' => 'Inst '.$index,
                'subdomain' => 'inst-'.$index.'-'.uniqid(),
                'status' => 'active',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('clients.index'))->assertOk();

        $clientSelectQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => preg_match('/from [`"]?clients[`"]?/i', $query) === 1)
            ->count();

        $this->assertLessThanOrEqual(4, $clientSelectQueries);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeClient(array $attributes = []): Client
    {
        return Client::query()->create(array_merge([
            'company_name' => 'Entreprise '.uniqid(),
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ], $attributes));
    }
}
