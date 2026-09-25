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

class PaymentIndexFiltersTest extends TestCase
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

    public function test_index_without_filters_returns_all_payments(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PENDING,
        ]);

        Payment::query()->create([
            'subscription_id' => $this->makeSubscription()->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Payments/Index')
                ->has('payments.data', 2)
                ->where('filters.status', null)
                ->where('filters.overdue', null));
    }

    public function test_index_filters_by_pending_status(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $pending = $this->makePayment($subscription, ['status' => Payment::STATUS_PENDING]);
        $this->makePayment($this->makeSubscription(), ['status' => Payment::STATUS_PAID, 'paid_at' => '2026-06-01 10:00:00']);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_PENDING]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $pending->id)
                ->where('filters.status', Payment::STATUS_PENDING));
    }

    public function test_index_filters_by_paid_status(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $paid = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-10 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_PAID]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $paid->id));
    }

    public function test_index_filters_by_failed_status(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $failed = $this->makePayment($subscription, ['status' => Payment::STATUS_FAILED]);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_FAILED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $failed->id));
    }

    public function test_index_filters_by_refunded_status(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $refunded = $this->makePayment($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => '2026-06-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_REFUNDED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $refunded->id));
    }

    public function test_index_rejects_invalid_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => 'invalid']))
            ->assertSessionHasErrors('status');
    }

    public function test_index_filters_overdue_payments(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-10 08:00:00',
        ]);

        $this->makePayment($this->makeSubscription(), [
            'status' => Payment::STATUS_FAILED,
            'due_at' => '2026-06-14 23:59:59',
        ]);

        $this->makePayment($this->makeSubscription(), [
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-20 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 2)
                ->where('filters.overdue', 1));
    }

    public function test_paid_payment_with_past_due_at_is_not_overdue(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-01 10:00:00',
            'due_at' => '2026-06-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0));
    }

    public function test_pending_payment_without_due_at_is_not_overdue(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'due_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0));
    }

    public function test_pending_payment_with_future_due_at_is_not_overdue(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-20 12:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0));
    }

    public function test_failed_payment_with_past_due_at_is_overdue(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $failed = $this->makePayment($subscription, [
            'status' => Payment::STATUS_FAILED,
            'due_at' => '2026-06-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $failed->id));
    }

    public function test_refunded_payment_with_past_due_at_is_not_overdue(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePayment($subscription, [
            'status' => Payment::STATUS_REFUNDED,
            'paid_at' => '2026-06-01 10:00:00',
            'due_at' => '2026-06-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0));
    }

    public function test_index_applies_pending_and_overdue_filters_together(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $match = $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-10 00:00:00',
        ]);

        $this->makePayment($this->makeSubscription(), [
            'status' => Payment::STATUS_FAILED,
            'due_at' => '2026-06-10 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', [
                'status' => Payment::STATUS_PENDING,
                'overdue' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $match->id)
                ->where('filters.status', Payment::STATUS_PENDING)
                ->where('filters.overdue', 1));
    }

    public function test_index_applies_failed_and_overdue_filters_together(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $match = $this->makePayment($subscription, [
            'status' => Payment::STATUS_FAILED,
            'due_at' => '2026-06-05 00:00:00',
        ]);

        $this->makePayment($this->makeSubscription(), [
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-05 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', [
                'status' => Payment::STATUS_FAILED,
                'overdue' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $match->id));
    }

    public function test_paid_and_overdue_filters_return_no_payments(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();

        $this->makePayment($subscription, [
            'status' => Payment::STATUS_PAID,
            'paid_at' => '2026-06-01 10:00:00',
            'due_at' => '2026-06-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', [
                'status' => Payment::STATUS_PAID,
                'overdue' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 0));
    }

    public function test_index_exposes_validated_filters_in_inertia_props(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $this->makePayment($subscription, [
            'status' => Payment::STATUS_PENDING,
            'due_at' => '2026-06-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('filters')
                ->where('filters.overdue', 1)
                ->where('filters.status', null));
    }

    public function test_pagination_preserves_query_parameters(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 16; $index++) {
            $subscription = $this->makeSubscription();
            $this->makePayment($subscription, [
                'status' => Payment::STATUS_PENDING,
                'due_at' => '2026-06-01 00:00:00',
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('payments.index', ['overdue' => 1]))
            ->assertOk();

        $pageData = $response->viewData('page');
        $links = $pageData['props']['payments']['links'] ?? [];

        $pageTwoLink = collect($links)->first(
            fn (array $link) => str_contains((string) ($link['url'] ?? ''), 'page=2'),
        );

        $this->assertNotNull($pageTwoLink);
        $this->assertStringContainsString('overdue=1', (string) $pageTwoLink['url']);
    }

    public function test_index_inertia_page_includes_filter_props_for_ui(): void
    {
        $user = User::factory()->create();
        $subscription = $this->makeSubscription();
        $this->makePayment($subscription, ['status' => Payment::STATUS_PENDING]);

        $this->actingAs($user)
            ->get(route('payments.index', ['status' => Payment::STATUS_PENDING]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Payments/Index')
                ->has('filters')
                ->where('filters.status', Payment::STATUS_PENDING));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(Subscription $subscription, array $attributes = []): Payment
    {
        return Payment::query()->create(array_merge([
            'subscription_id' => $subscription->id,
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
