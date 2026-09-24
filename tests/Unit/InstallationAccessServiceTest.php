<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Services\InstallationAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private InstallationAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InstallationAccessService::class);
    }

    public function test_active_subscription_makes_installation_accessible(): void
    {
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertTrue($this->service->isAccessible($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_grace_period_subscription_makes_installation_accessible(): void
    {
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);

        $this->assertTrue($this->service->isAccessible($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_suspended_subscription_makes_installation_not_accessible(): void
    {
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        $this->assertFalse($this->service->isAccessible($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_terminated_subscription_makes_installation_not_accessible(): void
    {
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $this->assertFalse($this->service->isAccessible($installation));
        $this->assertSame('no_subscription', $this->service->accessStatus($installation));
        $this->assertNull($this->service->latestSubscription($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_installation_without_subscription_is_not_accessible(): void
    {
        $installation = $this->makeInstallation();

        $this->assertFalse($this->service->isAccessible($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_unknown_subscription_status_is_not_accessible(): void
    {
        $installation = $this->makeInstallationWithSubscription([
            'status' => 'unknown',
        ]);

        $this->assertFalse($this->service->isAccessible($installation));
        $this->assertSame('no_subscription', $this->service->accessStatus($installation));
        $this->assertNull($this->service->latestSubscription($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_access_status_returns_expected_values(): void
    {
        $active = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $grace = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
        ]);
        $suspended = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
        ]);
        $terminated = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_TERMINATED,
        ]);
        $none = $this->makeInstallation();

        $this->assertSame('accessible', $this->service->accessStatus($active));
        $this->assertSame('accessible', $this->service->accessStatus($grace));
        $this->assertSame('suspended', $this->service->accessStatus($suspended));
        $this->assertSame('no_subscription', $this->service->accessStatus($terminated));
        $this->assertSame('no_subscription', $this->service->accessStatus($none));
    }

    public function test_non_terminated_subscription_is_selected_when_terminated_also_exists(): void
    {
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 10000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $installation = $installation->fresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->service->latestSubscription($installation)?->status);
        $this->assertTrue($this->service->isAccessible($installation));
        $this->assertSame('accessible', $this->service->accessStatus($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
    }

    public function test_multiple_terminated_subscriptions_without_non_terminated_yield_no_subscription(): void
    {
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 10000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $installation = $installation->fresh();

        $this->assertNull($this->service->latestSubscription($installation));
        $this->assertFalse($this->service->isAccessible($installation));
        $this->assertSame('no_subscription', $this->service->accessStatus($installation));
    }

    public function test_terminated_plus_grace_period_selects_grace_period(): void
    {
        $installation = $this->makeInstallationWithSubscriptions([
            ['status' => Subscription::STATUS_TERMINATED],
            ['status' => Subscription::STATUS_GRACE_PERIOD],
        ]);

        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $this->service->latestSubscription($installation)?->status);
        $this->assertTrue($this->service->isAccessible($installation));
    }

    public function test_terminated_plus_suspended_selects_suspended(): void
    {
        $installation = $this->makeInstallationWithSubscriptions([
            ['status' => Subscription::STATUS_TERMINATED],
            ['status' => Subscription::STATUS_SUSPENDED],
        ]);

        $this->assertSame(Subscription::STATUS_SUSPENDED, $this->service->latestSubscription($installation)?->status);
        $this->assertFalse($this->service->isAccessible($installation));
        $this->assertSame('suspended', $this->service->accessStatus($installation));
    }

    public function test_newer_terminated_subscription_does_not_mask_active_subscription(): void
    {
        $installation = $this->makeInstallation();

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        Subscription::query()->create([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_TERMINATED,
        ]);

        $installation = $installation->fresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->service->latestSubscription($installation)?->status);
        $this->assertTrue($this->service->isAccessible($installation));
        $this->assertSame('accessible', $this->service->accessStatus($installation));
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
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $subscriptionAttributes
     */
    private function makeInstallationWithSubscription(array $subscriptionAttributes = []): Installation
    {
        $installation = $this->makeInstallation();

        Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $subscriptionAttributes));

        return $installation->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $subscriptionsAttributes
     */
    private function makeInstallationWithSubscriptions(array $subscriptionsAttributes): Installation
    {
        $installation = $this->makeInstallation();

        foreach ($subscriptionsAttributes as $attributes) {
            Subscription::query()->create(array_merge([
                'installation_id' => $installation->id,
                'amount' => 15000,
                'currency' => 'XOF',
                'status' => Subscription::STATUS_ACTIVE,
            ], $attributes));
        }

        return $installation->fresh();
    }

    private function assertInstallationUnchangedAfterAccessCheck(Installation $installation): void
    {
        $before = $installation->fresh()->only(['status', 'suspended_at', 'terminated_at', 'name', 'subdomain']);

        $this->service->isAccessible($installation);
        $this->service->accessStatus($installation);

        $this->assertSame(
            $before,
            $installation->fresh()->only(['status', 'suspended_at', 'terminated_at', 'name', 'subdomain']),
        );
    }
}
