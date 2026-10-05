<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('payments.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_index_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->has('payments.data', 1)
                ->has('indicators'));
    }

    public function test_empty_state_when_no_payments(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0)
                ->where('indicators.total', 0));
    }

    public function test_index_orders_by_created_at_desc(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
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
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $newer->id)
                ->where('payments.data.1.id', $older->id));
    }

    public function test_pagination_preserves_filters(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($index = 0; $index < 16; $index++) {
            $this->makePayment(['status' => Payment::STATUS_PAID, 'paid_at' => '2026-06-01 10:00:00']);
        }

        $response = $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_PAID]))
            ->assertOk();

        $links = $response->viewData('page')['props']['payments']['links'] ?? [];
        $pageTwo = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwo);
        $this->assertStringContainsString('status=paid', (string) $pageTwo['url']);
    }

    public function test_index_filters_by_status(): void
    {
        $user = $this->controlCenterAdminUser();
        $pending = $this->makePayment(['status' => Payment::STATUS_PENDING]);
        $this->makePayment(['status' => Payment::STATUS_PAID, 'paid_at' => '2026-06-01 10:00:00']);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_PENDING]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $pending->id));
    }

    public function test_index_filters_by_client_id(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Client Paiement',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = $this->makeInstallation(['client_id' => $client->id]);
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $match = $this->makePayment(['subscription_id' => $subscription->id]);
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index', ['client_id' => $client->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.client.id', $client->id)
                ->where('payments.data.0.id', $match->id));
    }

    public function test_index_filters_by_installation_id(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $match = $this->makePayment(['subscription_id' => $subscription->id]);
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index', ['installation_id' => $installation->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.installation.id', $installation->id)
                ->where('payments.data.0.id', $match->id));
    }

    public function test_index_filters_by_subscription_id(): void
    {
        $user = $this->controlCenterAdminUser();
        $subscription = $this->makeSubscription();
        $match = $this->makePayment(['subscription_id' => $subscription->id]);
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index', ['subscription_id' => $subscription->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.subscription.id', $subscription->id)
                ->where('payments.data.0.id', $match->id));
    }

    public function test_search_by_client_name(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Entreprise Recherche Pay',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = $this->makeInstallation(['client_id' => $client->id]);
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $match = $this->makePayment(['subscription_id' => $subscription->id]);
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index', ['search' => 'Recherche Pay']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $match->id));
    }

    public function test_search_by_installation_name(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Site Pay Unique']);
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $match = $this->makePayment(['subscription_id' => $subscription->id]);
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index', ['search' => 'Site Pay Unique']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $match->id));
    }

    public function test_search_by_subdomain(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['subdomain' => 'pay-subdomain-xyz']);
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $match = $this->makePayment(['subscription_id' => $subscription->id]);

        $this->actingAs($user)
            ->get(route('payments.index', ['search' => 'pay-subdomain-xyz']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $match->id));
    }

    public function test_search_by_reference(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makePayment(['reference' => 'REF-ADMIN-9988']);
        $this->makePayment(['reference' => 'OTHER']);

        $this->actingAs($user)
            ->get(route('payments.index', ['search' => 'REF-ADMIN-9988']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $match->id));
    }

    public function test_date_from_filters_paid_at_for_paid_payments(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makePayment([
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-10 10:00:00',
        ]);
        $this->makePayment([
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-05-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['date_from' => '2026-06-01']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $match->id));
    }

    public function test_date_to_filters_paid_at_for_paid_payments(): void
    {
        $user = $this->controlCenterAdminUser();
        $match = $this->makePayment([
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-05-01 10:00:00',
            'created_at' => '2026-05-01 10:00:00',
        ]);
        $this->makePayment([
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-10 10:00:00',
            'created_at' => '2026-06-10 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['date_to' => '2026-05-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $match->id));
    }

    public function test_indicators_count_and_amounts(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makePayment([
            'status' => Payment::STATUS_PAID,
            'amount' => 20000,
            'paid_at' => '2026-06-01 10:00:00',
        ]);
        $this->makePayment([
            'status' => Payment::STATUS_PENDING,
            'amount' => 15000,
        ]);
        $this->makePayment([
            'status' => Payment::STATUS_REFUNDED,
            'amount' => 5000,
            'paid_at' => '2026-05-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('indicators.total', 3)
                ->where('indicators.paid', 1)
                ->where('indicators.pending', 1)
                ->where('indicators.refunded', 1)
                ->where('indicators.total_paid_amount', 20000)
                ->where('indicators.total_pending_amount', 15000)
                ->where('indicators.total_refunded_amount', 5000));
    }

    public function test_failed_status_listed(): void
    {
        $user = $this->controlCenterAdminUser();
        $failed = $this->makePayment(['status' => Payment::STATUS_FAILED]);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_FAILED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.id', $failed->id)
                ->where('payments.data.0.status', Payment::STATUS_FAILED));
    }

    public function test_payment_with_credit_fields(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makePayment([
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-01 10:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ]);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.credit_months_purchased', 3)
                ->where('payments.data.0.monthly_unit_amount', 15000));
    }

    public function test_payment_without_credit(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makePayment([
            'credit_months_purchased' => null,
            'monthly_unit_amount' => null,
        ]);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.credit_months_purchased', null));
    }

    public function test_installation_and_subscription_displayed(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation(['name' => 'Inst Pay']);
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $this->makePayment(['subscription_id' => $subscription->id]);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.installation.name', 'Inst Pay')
                ->where('payments.data.0.subscription.id', $subscription->id));
    }

    public function test_index_never_exposes_sensitive_fields(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation([
            'database_name' => 'pay_secret_db',
            'database_host' => 'db.pay.local',
        ]);
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $this->makePayment(['subscription_id' => $subscription->id]);

        $response = $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk();

        $encoded = json_encode($response->viewData('page')['props'] ?? []);

        $this->assertIsString($encoded);
        foreach (['database_name', 'database_host', 'password', 'secret', 'credentials', 'pay_secret_db'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_index_get_does_not_mutate_payment(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment(['amount' => 15000]);
        $before = $payment->fresh()->only(['amount', 'status']);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk();

        $this->assertSame($before, $payment->fresh()->only(['amount', 'status']));
    }

    public function test_index_does_not_send_email(): void
    {
        $user = $this->controlCenterAdminUser();
        $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_installation_show_accessible_from_list_context(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->makeInstallation();
        $subscription = $this->makeSubscription(['installation_id' => $installation->id]);
        $this->makePayment(['subscription_id' => $subscription->id]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk();
    }

    public function test_index_does_not_trigger_obvious_n_plus_one(): void
    {
        $user = $this->controlCenterAdminUser();

        for ($index = 0; $index < 5; $index++) {
            $this->makePayment(['subscription_id' => $this->makeSubscription(['installation_id' => $this->makeInstallation()->id])->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('payments.index'))->assertOk();

        $installationQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains(strtolower($query), ' from `installations`'))
            ->count();

        $this->assertLessThanOrEqual(3, $installationQueries);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(array $attributes = []): Payment
    {
        $subscriptionId = $attributes['subscription_id'] ?? $this->makeSubscription()->id;
        unset($attributes['subscription_id']);

        return Payment::query()->create(array_merge([
            'subscription_id' => $subscriptionId,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
        ], $attributes));
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
