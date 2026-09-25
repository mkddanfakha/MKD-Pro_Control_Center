<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationIndexFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_without_filters_returns_all_installations(): void
    {
        $user = User::factory()->create();

        $this->makeInstallation(['status' => 'active']);
        $this->makeInstallation(['status' => 'inactive']);
        $this->makeInstallation(['status' => 'suspended']);
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Index')
                ->has('installations.data', 4)
                ->where('filters.status', null));
    }

    public function test_index_filters_by_active_status(): void
    {
        $user = User::factory()->create();

        $active = $this->makeInstallation(['status' => 'active']);
        $this->makeInstallation(['status' => 'inactive']);
        $this->makeInstallation(['status' => 'suspended']);
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $active->id)
                ->where('filters.status', 'active'));
    }

    public function test_index_filters_by_inactive_status(): void
    {
        $user = User::factory()->create();

        $this->makeInstallation(['status' => 'active']);
        $inactive = $this->makeInstallation(['status' => 'inactive']);
        $this->makeInstallation(['status' => 'suspended']);
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $inactive->id)
                ->where('filters.status', 'inactive'));
    }

    public function test_index_filters_by_suspended_status(): void
    {
        $user = User::factory()->create();

        $this->makeInstallation(['status' => 'active']);
        $this->makeInstallation(['status' => 'inactive']);
        $suspended = $this->makeInstallation(['status' => 'suspended']);
        $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $suspended->id)
                ->where('filters.status', 'suspended'));
    }

    public function test_index_filters_by_terminated_status(): void
    {
        $user = User::factory()->create();

        $this->makeInstallation(['status' => 'active']);
        $this->makeInstallation(['status' => 'inactive']);
        $this->makeInstallation(['status' => 'suspended']);
        $terminated = $this->makeInstallation(['status' => 'terminated']);

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'terminated']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.id', $terminated->id)
                ->where('filters.status', 'terminated'));
    }

    public function test_index_rejects_invalid_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'invalid']))
            ->assertSessionHasErrors('status');
    }

    public function test_pagination_preserves_query_parameters(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 16; $index++) {
            $this->makeInstallation(['status' => 'suspended']);
        }

        $response = $this->actingAs($user)
            ->get(route('installations.index', ['status' => 'suspended']))
            ->assertOk();

        $pageData = $response->viewData('page');
        $links = $pageData['props']['installations']['links'] ?? [];

        $pageTwoLink = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwoLink);
        $this->assertStringContainsString('status=suspended', (string) $pageTwoLink['url']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstallation(array $attributes = []): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ], $attributes));
    }
}
