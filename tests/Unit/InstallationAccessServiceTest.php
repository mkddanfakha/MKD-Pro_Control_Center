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
        $this->assertSame('suspended', $this->service->accessStatus($installation));
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
        $this->assertSame('terminated', $this->service->accessStatus($terminated));
        $this->assertSame('no_subscription', $this->service->accessStatus($none));
    }

    public function test_latest_subscription_by_id_is_used_when_multiple_exist(): void
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

        $this->assertTrue($this->service->isAccessible($installation));
        $this->assertSame('accessible', $this->service->accessStatus($installation));
        $this->assertInstallationUnchangedAfterAccessCheck($installation);
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
