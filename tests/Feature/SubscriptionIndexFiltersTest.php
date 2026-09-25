<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionIndexFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_index_without_filters_returns_all_subscriptions(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        Subscription::query()->create([
            'installation_id' => $this->makeInstallation()->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->has('subscriptions.data', 2)
                ->where('filters.status', null)
                ->where('filters.expiring_within_days', null));
    }

    public function test_index_filters_by_active_status(): void
    {
        $user = User::factory()->create();
        $active = $this->makeSubscription(['status' => Subscription::STATUS_ACTIVE]);
        $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => Subscription::STATUS_ACTIVE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $active->id)
                ->where('filters.status', Subscription::STATUS_ACTIVE));
    }

    public function test_index_filters_by_grace_period_status(): void
    {
        $user = User::factory()->create();
        $grace = $this->makeSubscription(['status' => Subscription::STATUS_GRACE_PERIOD]);
        $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => Subscription::STATUS_GRACE_PERIOD]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $grace->id));
    }

    public function test_index_filters_by_suspended_status(): void
    {
        $user = User::factory()->create();
        $suspended = $this->makeSubscription(['status' => Subscription::STATUS_SUSPENDED]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => Subscription::STATUS_SUSPENDED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $suspended->id));
    }

    public function test_index_filters_by_terminated_status(): void
    {
        $user = User::factory()->create();
        $terminated = $this->makeSubscription(['status' => Subscription::STATUS_TERMINATED]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => Subscription::STATUS_TERMINATED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $terminated->id));
    }

    public function test_index_rejects_invalid_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => 'invalid']))
            ->assertSessionHasErrors('status');
    }

    public function test_index_filters_expiring_within_seven_days(): void
    {
        $user = User::factory()->create();

        $expiringSoon = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-06-20 18:00:00',
        ]);

        $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-07-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['expiring_within_days' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $expiringSoon->id)
                ->where('filters.expiring_within_days', 7));
    }

    public function test_index_excludes_period_end_outside_seven_day_window(): void
    {
        $user = User::factory()->create();

        $inside = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-06-22 12:00:00',
        ]);

        $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-06-23 00:00:01',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['expiring_within_days' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $inside->id));
    }

    public function test_expiring_filter_excludes_grace_period_even_with_soon_end_date(): void
    {
        $user = User::factory()->create();

        $this->makeSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'current_period_end' => '2026-06-18 12:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['expiring_within_days' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 0));
    }

    public function test_expiring_filter_excludes_suspended_even_with_soon_end_date(): void
    {
        $user = User::factory()->create();

        $this->makeSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'current_period_end' => '2026-06-18 12:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['expiring_within_days' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 0));
    }

    public function test_index_applies_status_and_expiring_filters_together(): void
    {
        $user = User::factory()->create();

        $match = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-06-19 10:00:00',
        ]);

        $this->makeSubscription([
            'installation_id' => $this->makeInstallation()->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-08-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', [
                'status' => Subscription::STATUS_ACTIVE,
                'expiring_within_days' => 7,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $match->id)
                ->where('filters.status', Subscription::STATUS_ACTIVE)
                ->where('filters.expiring_within_days', 7));
    }

    public function test_index_exposes_validated_filters_in_inertia_props(): void
    {
        $user = User::factory()->create();
        $this->makeSubscription(['status' => Subscription::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => Subscription::STATUS_ACTIVE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('filters')
                ->where('filters.status', Subscription::STATUS_ACTIVE)
                ->where('filters.expiring_within_days', null));
    }

    public function test_pagination_preserves_query_parameters(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 16; $index++) {
            $this->makeSubscription([
                'installation_id' => $this->makeInstallation()->id,
                'status' => Subscription::STATUS_ACTIVE,
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('subscriptions.index', ['status' => Subscription::STATUS_ACTIVE]))
            ->assertOk();

        $pageData = $response->viewData('page');
        $links = $pageData['props']['subscriptions']['links'] ?? [];

        $pageTwoLink = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwoLink);
        $this->assertStringContainsString('status=active', (string) $pageTwoLink['url']);
    }

    public function test_index_inertia_page_includes_filter_props_for_ui(): void
    {
        $user = User::factory()->create();
        $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-06-17 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('subscriptions.index', [
                'status' => Subscription::STATUS_ACTIVE,
                'expiring_within_days' => 7,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->has('filters')
                ->where('filters.status', Subscription::STATUS_ACTIVE)
                ->where('filters.expiring_within_days', 7));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $installationId = $attributes['installation_id'] ?? $this->makeInstallation()->id;
        unset($attributes['installation_id']);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installationId,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
