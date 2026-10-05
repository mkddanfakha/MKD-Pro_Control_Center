<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentShowAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $payment = $this->makePayment();

        $this->get(route('payments.show', $payment))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_control_center_access_receives_forbidden(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $payment = $this->makePayment();

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_show_page(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment(['amount' => 22000]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Show')
                ->where('payment.id', $payment->id)
                ->where('payment.amount', 22000)
                ->has('navigation'));
    }

    public function test_missing_payment_returns_not_found(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get('/payments/999999')
            ->assertNotFound();
    }

    public function test_general_payment_fields_are_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment([
            'reference' => 'REF-SHOW-1',
            'payment_method' => 'virement',
            'notes' => 'Note admin',
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.amount', (int) $payment->amount)
                ->where('payment.currency', 'XOF')
                ->where('payment.status', Payment::STATUS_PAID)
                ->where('payment.reference', 'REF-SHOW-1')
                ->where('payment.payment_method', 'virement')
                ->where('payment.notes', 'Note admin')
                ->has('payment.created_at')
                ->has('payment.updated_at'));
    }

    public function test_credit_fields_and_period_are_exposed(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment([
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
            'credit_exhausted_at' => '2026-08-01 00:00:00',
            'period_start' => '2026-06-01 00:00:00',
            'period_end' => '2026-06-30 23:59:59',
            'renewal_applied_at' => '2026-06-02 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.monthly_unit_amount', 15000)
                ->where('credit.credit_months_purchased', 3)
                ->where('credit.credit_exhausted_at', '2026-08-01 00:00:00')
                ->where('payment.period_start', '2026-06-01 00:00:00')
                ->where('payment.period_end', '2026-06-30 23:59:59')
                ->where('payment.renewal_applied_at', '2026-06-02 10:00:00'));
    }

    public function test_subscription_installation_and_client_context(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment();
        $subscription = Subscription::query()->findOrFail($payment->subscription_id);
        $installation = Installation::query()->findOrFail($subscription->installation_id);
        $client = Client::query()->findOrFail($installation->client_id);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.id', $subscription->id)
                ->where('installation.id', $installation->id)
                ->where('client.id', $client->id)
                ->where('client.company_name', $client->company_name)
                ->where('navigation.subscription_show', route('subscriptions.show', $subscription))
                ->where('navigation.installation_show', route('installations.show', $installation))
                ->where('navigation.client_show', route('clients.show', $client))
                ->where('navigation.payments_index', route('payments.index')));
    }

    public function test_consumptions_linked_to_payment_are_listed(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment();
        $subscriptionId = $payment->subscription_id;

        SubscriptionPaymentConsumption::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscriptionId,
            'period_start' => '2026-07-01 00:00:00',
            'period_end' => '2026-07-31 23:59:59',
            'consumed_at' => '2026-07-05 09:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('consumptions', 1)
                ->where('consumptions.0.payment_id', $payment->id)
                ->where('consumptions.0.subscription_id', $subscriptionId));
    }

    public function test_payment_audit_history_and_filtered_audit_link(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment();

        AuditLog::query()->create([
            'action' => 'payment.created',
            'auditable_type' => $payment->getMorphClass(),
            'auditable_id' => $payment->id,
            'result' => 'success',
        ]);

        AuditLog::query()->create([
            'action' => 'payment.renewal_applied',
            'auditable_type' => $payment->getMorphClass(),
            'auditable_id' => $payment->id,
            'result' => 'success',
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit_history', 2)
                ->where('audit_history.0.action', 'payment.renewal_applied')
                ->where(
                    'navigation.audit_logs_index',
                    route('audit-logs.index', [
                        'auditable_type' => $payment->getMorphClass(),
                        'auditable_id' => $payment->id,
                    ]),
                ));
    }

    public function test_installation_payload_excludes_database_secrets(): void
    {
        $user = $this->controlCenterAdminUser();
        $client = Client::query()->create([
            'company_name' => 'Secret Co',
            'contact_name' => 'Contact',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Secret Site',
            'subdomain' => 'secret-'.uniqid(),
            'status' => 'active',
            'database_name' => 'hidden_db',
            'database_host' => '10.0.0.99',
        ]);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => '2026-12-01 00:00:00',
        ]);
        $payment = Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installation')
                ->where('installation.name', 'Secret Site')
                ->missing('installation.database_name')
                ->missing('installation.database_host'));
    }

    public function test_show_is_read_only_get_route_only(): void
    {
        $user = $this->controlCenterAdminUser();
        $payment = $this->makePayment();
        $amountBefore = $payment->fresh()->amount;

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk();

        $this->assertSame($amountBefore, $payment->fresh()->amount);

        $showRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => $route->getName() === 'payments.show',
        );

        foreach ($showRoutes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_show_vue_has_no_business_action_endpoints(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Payments/Show.vue'));

        $this->assertStringNotContainsString('renew-subscription', $contents);
        $this->assertStringNotContainsString('consume-credit', $contents);
        $this->assertStringNotContainsString('router.post', $contents);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(array $attributes = []): Payment
    {
        $client = Client::query()->create([
            'company_name' => 'Pay Show Client',
            'contact_name' => 'Contact',
            'email' => 'pay@example.com',
            'phone' => '+221000000',
            'status' => 'active',
        ]);
        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Pay Show Install',
            'subdomain' => 'pay-show-'.uniqid(),
            'domain' => 'pay.example.com',
            'status' => 'active',
            'version' => '1.0.0',
        ]);
        $subscription = Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-01-01 00:00:00',
            'current_period_start' => '2026-06-01 00:00:00',
            'current_period_end' => '2026-07-01 00:00:00',
        ]);

        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-10 12:00:00',
            'monthly_unit_amount' => 15000,
            'credit_months_purchased' => 3,
        ], $attributes));
    }
}
