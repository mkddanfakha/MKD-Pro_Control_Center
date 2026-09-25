<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardClientStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_active_clients_prop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('activeClients'));
    }

    public function test_dashboard_exposes_inactive_clients_prop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('inactiveClients'));
    }

    public function test_dashboard_client_statistics_match_database_statuses(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeClients', 2)
                ->where('inactiveClients', 1));
    }

    public function test_dashboard_total_clients_remains_global_count(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'inactive']);
        $this->makeClient(['status' => 'inactive']);

        $this->assertSame(3, Client::query()->count());

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalClients', 3));
    }

    public function test_dashboard_active_plus_inactive_equals_total_for_business_statuses(): void
    {
        $user = User::factory()->create();

        $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'active']);
        $this->makeClient(['status' => 'inactive']);
        $this->makeClient(['status' => 'inactive']);
        $this->makeClient(['status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalClients', 5)
                ->where('activeClients', 2)
                ->where('inactiveClients', 3));
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
