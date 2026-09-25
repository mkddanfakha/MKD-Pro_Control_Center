<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardAlertDeepLinksTest extends TestCase
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

    public function test_dashboard_exposes_positive_alert_counts_when_data_matches_filter_rules(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-06-20 18:00:00',
        ]);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-10 08:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('subscriptionsExpiringSoon', 1)
                ->where('overduePayments', 1));
    }

    public function test_dashboard_vue_subscription_alert_uses_expiring_filter_deep_link(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('v-if="subscriptionsExpiringSoon > 0"', $contents);
        $this->assertStringContainsString('href="/subscriptions?expiring_within_days=7"', $contents);
    }

    public function test_dashboard_vue_payment_alert_uses_overdue_filter_deep_link(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('v-if="overduePayments > 0"', $contents);
        $this->assertStringContainsString('href="/payments?overdue=1"', $contents);
    }

    public function test_dashboard_vue_subscription_status_cards_use_status_filter_deep_links(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('href="/subscriptions?status=active"', $contents);
        $this->assertStringContainsString('href="/subscriptions?status=grace_period"', $contents);
        $this->assertStringContainsString('href="/subscriptions?status=suspended"', $contents);
        $this->assertStringContainsString('href="/subscriptions?status=terminated"', $contents);
    }

    public function test_dashboard_vue_payment_status_cards_use_status_filter_deep_links(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('href="/payments?status=paid"', $contents);
        $this->assertStringContainsString('href="/payments?status=pending"', $contents);
        $this->assertStringContainsString('href="/payments?status=failed"', $contents);
        $this->assertStringContainsString('href="/payments?status=refunded"', $contents);
    }

    public function test_dashboard_vue_alert_deep_links_remain_present(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('href="/subscriptions?expiring_within_days=7"', $contents);
        $this->assertStringContainsString('href="/payments?overdue=1"', $contents);
    }

    public function test_dashboard_vue_installation_status_cards_use_status_filter_deep_links(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('href="/installations?status=active"', $contents);
        $this->assertStringContainsString('href="/installations?status=suspended"', $contents);
        $this->assertStringContainsString('href="/installations?status=terminated"', $contents);
        $this->assertStringNotContainsString('href="/installations?status=inactive"', $contents);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubscription(array $attributes = []): Subscription
    {
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);

        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }
}
