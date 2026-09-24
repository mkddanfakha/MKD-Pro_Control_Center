<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionUniquenessConstraintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $firstStatus
     * @param  list<string>  $secondStatus
     */
    #[DataProvider('conflictingNonTerminatedStatusPairsProvider')]
    public function test_two_non_terminated_subscriptions_on_same_installation_fail_at_sql_level(
        string $firstStatus,
        string $secondStatus,
    ): void {
        $installation = $this->makeInstallation();

        $this->createSubscriptionOnInstallation($installation, ['status' => $firstStatus]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createSubscriptionOnInstallation($installation, ['status' => $secondStatus]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function conflictingNonTerminatedStatusPairsProvider(): array
    {
        return [
            'active + active' => [Subscription::STATUS_ACTIVE, Subscription::STATUS_ACTIVE],
            'active + grace_period' => [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE_PERIOD],
            'active + suspended' => [Subscription::STATUS_ACTIVE, Subscription::STATUS_SUSPENDED],
            'grace_period + suspended' => [Subscription::STATUS_GRACE_PERIOD, Subscription::STATUS_SUSPENDED],
        ];
    }

    public function test_multiple_terminated_subscriptions_on_same_installation_are_allowed(): void
    {
        $installation = $this->makeInstallation();

        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_TERMINATED]);
        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_TERMINATED]);
        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_TERMINATED]);

        $this->assertSame(3, Subscription::query()->where('installation_id', $installation->id)->count());
    }

    public function test_terminated_then_active_on_same_installation_is_allowed(): void
    {
        $installation = $this->makeInstallation();

        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_TERMINATED]);
        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_ACTIVE]);

        $this->assertSame(2, Subscription::query()->where('installation_id', $installation->id)->count());
    }

    public function test_terminated_then_grace_period_on_same_installation_is_allowed(): void
    {
        $installation = $this->makeInstallation();

        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_TERMINATED]);
        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_GRACE_PERIOD]);

        $this->assertSame(2, Subscription::query()->where('installation_id', $installation->id)->count());
    }

    public function test_terminated_then_suspended_on_same_installation_is_allowed(): void
    {
        $installation = $this->makeInstallation();

        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_TERMINATED]);
        $this->createSubscriptionOnInstallation($installation, ['status' => Subscription::STATUS_SUSPENDED]);

        $this->assertSame(2, Subscription::query()->where('installation_id', $installation->id)->count());
    }

    public function test_active_subscriptions_on_different_installations_are_allowed(): void
    {
        $installationA = $this->makeInstallation();
        $installationB = $this->makeInstallation();

        $this->createSubscriptionOnInstallation($installationA, ['status' => Subscription::STATUS_ACTIVE]);
        $this->createSubscriptionOnInstallation($installationB, ['status' => Subscription::STATUS_ACTIVE]);

        $this->assertSame(1, Subscription::query()->where('installation_id', $installationA->id)->count());
        $this->assertSame(1, Subscription::query()->where('installation_id', $installationB->id)->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSubscriptionOnInstallation(Installation $installation, array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function makeInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Client Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'sub-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
