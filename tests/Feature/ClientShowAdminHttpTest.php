<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientShowAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $client = $this->makeClient();

        $this->get(route('clients.show', $client))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $client = $this->makeClient();

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_show_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient(['company_name' => 'Fiche Client Alpha']);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clients/Show')
                ->where('client.company_name', 'Fiche Client Alpha')
                ->where('client.id', $client->id));
    }

    public function test_show_displays_client_without_installations(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 0)
                ->where('statistics.installations.total', 0)
                ->where('statistics.subscriptions.active', 0)
                ->has('subscriptions.data', 0)
                ->has('payments.data', 0)
                ->has('reminders.data', 0));
    }

    public function test_show_lists_installations_and_statistics(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $this->makeInstallation($client, ['status' => 'active', 'name' => 'Site Actif']);
        $this->makeInstallation($client, ['status' => 'suspended', 'name' => 'Site Suspendu']);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 2)
                ->where('statistics.installations.total', 2)
                ->where('statistics.installations.active', 1)
                ->where('statistics.installations.suspended', 1));
    }

    public function test_show_installation_statistics_expose_all_status_counters_with_terminated_key(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        $this->makeInstallation($client, ['status' => 'active', 'subdomain' => 'inst-active-'.uniqid()]);
        $this->makeInstallation($client, ['status' => 'inactive', 'subdomain' => 'inst-inactive-'.uniqid()]);
        $this->makeInstallation($client, ['status' => 'suspended', 'subdomain' => 'inst-suspended-'.uniqid()]);
        $this->makeInstallation($client, ['status' => 'terminated', 'subdomain' => 'inst-terminated-'.uniqid()]);

        $response = $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk();

        $statistics = $response->original->getData()['page']['props']['statistics']['installations'] ?? null;
        $this->assertIsArray($statistics);
        $this->assertArrayHasKey('terminated', $statistics);
        $this->assertArrayNotHasKey('terminated_count', $statistics);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('statistics.installations.total', 4)
            ->where('statistics.installations.active', 1)
            ->where('statistics.installations.inactive', 1)
            ->where('statistics.installations.suspended', 1)
            ->where('statistics.installations.terminated', 1));
    }

    public function test_show_subscription_statistics_match_statuses(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installationActive = $this->makeInstallation($client, ['subdomain' => 'stat-active-'.uniqid()]);
        $installationGrace = $this->makeInstallation($client, ['subdomain' => 'stat-grace-'.uniqid()]);

        Subscription::query()->create([
            'installation_id' => $installationActive->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        Subscription::query()->create([
            'installation_id' => $installationGrace->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);
        Subscription::query()->create([
            'installation_id' => $installationActive->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.subscriptions.active', 1)
                ->where('statistics.subscriptions.grace_period', 1)
                ->where('statistics.subscriptions.terminated', 1));
    }

    public function test_show_lists_subscriptions_including_terminated_history(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);

        $terminated = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 10000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
            'current_period_end' => '2026-01-31 23:59:59',
        ]);
        $current = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 20000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-12-31 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions.data', 2)
                ->where('subscriptions.data.0.id', $current->id)
                ->where('subscriptions.data.1.id', $terminated->id)
                ->where('subscriptions.data.0.installation_name', $installation->name));
    }

    public function test_show_lists_payments_for_client(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-01 10:00:00',
            'reference' => 'REF-CLIENT-1',
            'created_at' => '2026-06-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $payment->id)
                ->where('payments.data.0.installation_name', $installation->name)
                ->where('payments.data.0.subscription_id', $subscription->id));
    }

    public function test_show_lists_reminders_for_client(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $reminder = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-20 00:00:00',
            'detected_at' => '2026-10-20 08:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 1)
                ->where('reminders.data.0.id', $reminder->id)
                ->where('reminders.data.0.installation_name', $installation->name));
    }

    public function test_show_without_payments_or_reminders(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $this->makeInstallation($client);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0)
                ->has('reminders.data', 0));
    }

    public function test_user_can_navigate_to_installation_show_from_client_context(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.id', $installation->id));

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk();
    }

    public function test_installations_pagination_preserves_query_string(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        for ($index = 0; $index < 11; $index++) {
            $this->makeInstallation($client, ['subdomain' => 'pag-'.$index.'-'.uniqid()]);
        }

        $response = $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk();

        $links = $response->viewData('page')['props']['installations']['links'] ?? [];
        $pageTwo = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'installations_page=2'),
        );

        $this->assertNotNull($pageTwo);
    }

    public function test_show_never_exposes_sensitive_fields(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $this->makeInstallation($client, [
            'database_name' => 'secret_client_db',
            'database_host' => 'db.hidden.local',
        ]);

        $response = $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk();

        $encoded = json_encode($response->viewData('page')['props'] ?? []);

        $this->assertIsString($encoded);
        foreach (['database_name', 'database_host', 'password', 'secret', 'credentials', 'secret_client_db', 'db.hidden.local', 'installationOverviews'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_show_get_does_not_mutate_client(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient(['company_name' => 'Stable Corp']);
        $before = $client->fresh()->only(['company_name', 'status']);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk();

        $this->assertSame($before, $client->fresh()->only(['company_name', 'status']));
    }

    public function test_show_does_not_send_email(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();
        $installation = $this->makeInstallation($client);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 3,
            'scheduled_for' => '2026-10-01 00:00:00',
            'detected_at' => '2026-10-01 08:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_show_does_not_trigger_obvious_n_plus_one(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = $this->makeClient();

        for ($index = 0; $index < 3; $index++) {
            $installation = $this->makeInstallation($client, ['subdomain' => 'nplus-'.$index.'-'.uniqid()]);
            $subscription = Subscription::query()->create([
                'installation_id' => $installation->id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Subscription::STATUS_ACTIVE,
            ]);
            Payment::query()->create([
                'subscription_id' => $subscription->id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Payment::STATUS_PAID,
                'paid_at' => '2026-10-15 12:00:00',
                'created_at' => '2026-10-15 12:00:00',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('clients.show', $client))->assertOk();

        $subscriptionQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), ' from `subscriptions`'))
            ->count();

        $this->assertLessThanOrEqual(4, $subscriptionQueries);
    }

    public function test_show_returns_not_found_for_missing_client(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get('/clients/999999')
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeClient(array $attributes = []): Client
    {
        return Client::query()->create(array_merge([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstallation(Client $client, array $attributes = []): Installation
    {
        return Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ], $attributes));
    }
}
