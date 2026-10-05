<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardAdministrativeLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_authorized_user_can_view_dashboard(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_dashboard_vue_links_installations_with_status_filters(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('href="/installations"', $contents);
        $this->assertStringContainsString('href="/installations?status=active"', $contents);
        $this->assertStringContainsString('href="/installations?status=suspended"', $contents);
    }

    public function test_dashboard_vue_links_subscriptions_with_status_filters(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('adminUrls.subscriptions_index', $contents);
        $this->assertStringContainsString('href="/subscriptions?status=active"', $contents);
        $this->assertStringContainsString('href="/subscriptions?status=grace_period"', $contents);
        $this->assertStringContainsString('href="/subscriptions?status=suspended"', $contents);
    }

    public function test_dashboard_vue_links_subscription_due_period_filters(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString("period: 'due_7'", $contents);
        $this->assertStringContainsString("period: 'due_3'", $contents);
        $this->assertStringContainsString("period: 'due_1'", $contents);
        $this->assertStringContainsString("period: 'due_0'", $contents);
        $this->assertStringContainsString("period: 'expired'", $contents);
        $this->assertStringContainsString('subscriptionsFilter', $contents);
    }

    public function test_dashboard_vue_links_payments_with_status_filters(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('href="/payments"', $contents);
        $this->assertStringContainsString('href="/payments?status=paid"', $contents);
    }

    public function test_dashboard_vue_links_subscription_reminders_with_status_filters(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('adminUrls.subscription_reminders_index', $contents);
        $this->assertStringContainsString('href="/subscription-reminders?status=failed"', $contents);
    }

    public function test_dashboard_vue_does_not_link_to_unknown_admin_paths(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Dashboard.vue'));

        $this->assertStringNotContainsString('href="/admin/', $contents);
        $this->assertStringNotContainsString('href="/unknown-', $contents);
    }

    public function test_dashboard_get_does_not_mutate_data(): void
    {
        $user = $this->controlCenterAdminUser();
        Client::query()->create([
            'company_name' => 'Stable Client',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);

        $before = Client::query()->count();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame($before, Client::query()->count());
    }

    public function test_dashboard_get_does_not_send_email(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_named_admin_routes_exist(): void
    {
        $this->assertTrue(Route::has('clients.index'));
        $this->assertTrue(Route::has('installations.index'));
        $this->assertTrue(Route::has('subscriptions.index'));
        $this->assertTrue(Route::has('payments.index'));
        $this->assertTrue(Route::has('subscription-reminders.index'));
    }
}
