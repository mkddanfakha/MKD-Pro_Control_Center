<?php

namespace Tests\Feature\Commands;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SyncSubscriptionLifecycleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_with_no_subscriptions_succeeds(): void
    {
        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Total traité : 0', $output);
        $this->assertStringContainsString('Erreurs : 0', $output);
    }

    public function test_active_subscription_with_valid_period_stays_active(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-10-15 12:00:00');

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertStringContainsString('Inchangés : 1', $output);
    }

    public function test_expired_active_subscription_moves_to_grace_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-11-01 00:00:00');

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
        $this->assertStringContainsString('Active → Grace : 1', $output);
    }

    public function test_expired_grace_period_subscription_moves_to_suspended(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->travelTo('2026-11-08 00:00:00');

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertStringContainsString('Grace → Suspended : 1', $output);
    }

    public function test_suspended_subscription_is_not_processed(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => '2026-11-08 00:00:00',
        ]);

        $this->travelTo('2026-12-01 00:00:00');

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertStringContainsString('Total traité : 0', $output);
    }

    public function test_terminated_subscription_is_not_processed(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_TERMINATED,
            'terminated_at' => '2026-11-01 00:00:00',
        ]);

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(Subscription::STATUS_TERMINATED, $subscription->fresh()->status);
        $this->assertStringContainsString('Total traité : 0', $output);
    }

    public function test_invalid_subscription_is_counted_as_error_and_others_continue(): void
    {
        $valid = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $invalid = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->travelTo('2026-10-15 12:00:00');

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertSame(Subscription::STATUS_ACTIVE, $valid->fresh()->status);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $invalid->fresh()->status);
        $this->assertStringContainsString('Total traité : 2', $output);
        $this->assertStringContainsString('Erreurs : 1', $output);
        $this->assertStringContainsString("Abonnement #{$invalid->id} : erreur", $output);
    }

    public function test_quiet_option_hides_per_subscription_output(): void
    {
        $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-12-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-10-15 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle', ['--quiet' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('aucun changement', $output);
        $this->assertStringContainsString('Résumé', $output);
        $this->assertStringContainsString('Total traité : 1', $output);
    }

    public function test_second_execution_is_idempotent(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->travelTo('2026-11-02 12:00:00');

        Artisan::call('subscriptions:sync-lifecycle');
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);

        $exitCode = Artisan::call('subscriptions:sync-lifecycle');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $subscription->fresh()->status);
        $this->assertStringContainsString('Inchangés : 1', $output);
        $this->assertStringContainsString('Active → Grace : 0', $output);
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
            'subdomain' => 'test-'.uniqid(),
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
