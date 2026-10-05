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

class InstallationShowAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $installation = $this->makeInstallation();

        $this->get(route('installations.show', $installation))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_show_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Fiche Alpha']);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Show')
                ->where('installation.name', 'Fiche Alpha'));
    }

    public function test_show_displays_installation_and_client(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Entreprise Démo',
            'contact_name' => 'Jean Dupont',
            'email' => 'admin@demo.test',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Site Démo',
            'subdomain' => 'demo-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installation.id', $installation->id)
                ->where('installation.client.company_name', 'Entreprise Démo')
                ->where('installation.client.email', 'admin@demo.test'));
    }

    public function test_show_displays_current_subscription(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 18000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-12-31 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription.id', $subscription->id)
                ->where('current_subscription.status', Subscription::STATUS_ACTIVE)
                ->where('current_subscription.amount', 18000));
    }

    public function test_show_has_null_current_subscription_when_only_terminated(): void
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
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription', null));
    }

    public function test_show_lists_payments_ordered_by_created_at_desc(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $older = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-01-01 10:00:00',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $newer = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 30000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-01 10:00:00',
            'created_at' => '2026-06-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 2)
                ->where('payments.data.0.id', $newer->id)
                ->where('payments.data.1.id', $older->id));
    }

    public function test_show_lists_reminders_ordered_by_scheduled_for_desc(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $earlier = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 7,
            'scheduled_for' => '2026-10-01 00:00:00',
            'detected_at' => '2026-10-01 08:00:00',
            'status' => SubscriptionReminder::STATUS_DETECTED,
        ]);
        $later = SubscriptionReminder::query()->create([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => 3,
            'scheduled_for' => '2026-10-20 00:00:00',
            'detected_at' => '2026-10-20 08:00:00',
            'status' => SubscriptionReminder::STATUS_SENT,
            'sent_at' => '2026-10-20 09:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reminders.data', 2)
                ->where('reminders.data.0.id', $later->id)
                ->where('reminders.data.1.id', $earlier->id));
    }

    public function test_show_without_payments_or_reminders(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0)
                ->has('reminders.data', 0));
    }

    public function test_show_prefers_latest_non_terminated_subscription_as_current(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $current = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 20000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_subscription.id', $current->id)
                ->where('current_subscription.amount', 20000));
    }

    public function test_show_installation_statuses(): void
    {
        $user = $this->controlCenterAdminUser();

        foreach (['active', 'inactive', 'suspended', 'terminated'] as $status) {
            $installation = $this->makeInstallation(['status' => $status]);

            $this->actingAs($user)
                ->get(route('installations.show', $installation))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('installation.status', $status));
        }
    }

    public function test_show_never_exposes_sensitive_fields(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation([
            'database_name' => 'client_secret_db',
            'database_host' => 'db.internal.local',
        ]);

        $response = $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk();

        $props = $response->viewData('page')['props'] ?? [];
        $installationProps = json_encode($props['installation'] ?? []);

        $this->assertIsString($installationProps);
        foreach (['database_name', 'database_host', 'password', 'secret', 'credentials', 'client_secret_db', 'db.internal.local'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $installationProps);
        }
    }

    public function test_show_get_does_not_mutate_installation(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Stable']);

        $before = $installation->fresh()->only(['name', 'status']);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk();

        $after = $installation->fresh()->only(['name', 'status']);

        $this->assertSame($before, $after);
    }

    public function test_show_does_not_send_email(): void
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
            ->get(route('installations.show', $installation))
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_show_does_not_trigger_obvious_n_plus_one(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        for ($index = 0; $index < 5; $index++) {
            $createdAt = '2026-09-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' 12:00:00';
            Payment::query()->create([
                'subscription_id' => $subscription->id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Payment::STATUS_PAID,
                'paid_at' => $createdAt,
                'created_at' => $createdAt,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('installations.show', $installation))->assertOk();

        $paymentSelectQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), 'payments'))
            ->count();

        $this->assertLessThanOrEqual(4, $paymentSelectQueries);
    }

    public function test_show_returns_not_found_for_missing_installation(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get('/installations/999999')
            ->assertNotFound();
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
