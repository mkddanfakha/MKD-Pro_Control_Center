<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientIndexFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_without_filters_returns_all_clients(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 2)
                ->where('filters.status', null));
    }

    public function test_index_filters_by_active_status(): void
    {
        $user = User::factory()->create();

        $active = $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $active->id)
                ->where('clients.data.0.status', 'active')
                ->where('filters.status', 'active'));
    }

    public function test_index_filters_by_inactive_status(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'active']);
        $inactive = $this->makeClient(['status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $inactive->id)
                ->where('clients.data.0.status', 'inactive')
                ->where('filters.status', 'inactive'));
    }

    public function test_index_active_filter_excludes_inactive_clients(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'inactive', 'company_name' => 'Inactif Exclu']);
        $active = $this->makeClient(['status' => 'active', 'company_name' => 'Actif Seul']);

        $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $active->id)
                ->where('clients.data.0.status', 'active'));
    }

    public function test_index_inactive_filter_excludes_active_clients(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'active', 'company_name' => 'Actif Exclu']);
        $inactive = $this->makeClient(['status' => 'inactive', 'company_name' => 'Inactif Seul']);

        $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $inactive->id)
                ->where('clients.data.0.status', 'inactive'));
    }

    public function test_index_rejects_invalid_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'foo']))
            ->assertSessionHasErrors('status');
    }

    public function test_pagination_preserves_query_parameters(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 16; $index++) {
            $this->makeClient(['status' => 'active']);
        }

        $response = $this->actingAs($user)
            ->get(route('clients.index', ['status' => 'active']))
            ->assertOk();

        $pageData = $response->viewData('page');
        $links = $pageData['props']['clients']['links'] ?? [];

        $pageTwoLink = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwoLink);
        $this->assertStringContainsString('status=active', (string) $pageTwoLink['url']);
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
