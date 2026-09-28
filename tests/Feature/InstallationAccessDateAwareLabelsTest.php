<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationAccessDateAwareLabelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private static function accessLabelVuePaths(): array
    {
        return [
            'resources/js/Pages/Installations/Index.vue',
            'resources/js/Pages/Installations/Show.vue',
            'resources/js/Pages/Clients/Show.vue',
        ];
    }

    public function test_access_detail_label_helpers_distinguish_date_aware_cases(): void
    {
        foreach (self::accessLabelVuePaths() as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));
            $helper = $this->extractAccessDetailLabelHelper($contents, $relativePath);

            $this->assertStringContainsString('Période échue', $helper, $relativePath);
            $this->assertStringContainsString('Période de grâce échue', $helper, $relativePath);
            $this->assertStringContainsString("access.subscription_status === 'suspended'", $helper, $relativePath);
            $this->assertStringContainsString('Abonnement suspendu', $helper, $relativePath);
            $this->assertStringNotContainsString(
                "if (access.status === 'suspended')",
                $helper,
                $relativePath,
            );
        }
    }

    public function test_installation_show_exposes_expired_active_access_props(): void
    {
        $this->travelTo('2026-11-01 00:00:00');

        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-10-31 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.accessible', false)
                ->where('access.subscription_status', Subscription::STATUS_ACTIVE)
                ->where('access.status', 'suspended')
                ->where('lastSubscription.status', Subscription::STATUS_ACTIVE));
    }

    public function test_installation_show_exposes_expired_grace_period_access_props(): void
    {
        $this->travelTo('2026-11-08 00:00:00');

        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_GRACE_PERIOD,
            'current_period_end' => '2026-10-31 23:59:59',
            'grace_period_ends_at' => '2026-11-07 23:59:59',
        ]);

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.accessible', false)
                ->where('access.subscription_status', Subscription::STATUS_GRACE_PERIOD)
                ->where('access.status', 'suspended')
                ->where('lastSubscription.status', Subscription::STATUS_GRACE_PERIOD));
    }

    public function test_installation_index_exposes_real_suspended_access_props(): void
    {
        $user = User::factory()->create();
        $installation = $this->makeInstallationWithSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->get(route('installations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installations.data.0.access.accessible', false)
                ->where('installations.data.0.access.subscription_status', Subscription::STATUS_SUSPENDED)
                ->where('installations.data.0.access.status', 'suspended'));
    }

    public function test_client_show_exposes_no_subscription_access_props(): void
    {
        $user = User::factory()->create();
        $client = Client::query()->create([
            'company_name' => 'Société Test',
            'contact_name' => 'Contact Test',
            'status' => 'active',
        ]);

        Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Installation Test',
            'subdomain' => 'test-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installationOverviews.0.access.accessible', false)
                ->where('installationOverviews.0.access.subscription_status', null)
                ->where('installationOverviews.0.access.status', 'no_subscription'));
    }

    private function extractAccessDetailLabelHelper(string $contents, string $relativePath): string
    {
        if (! preg_match('/function accessDetailLabel\(access\)\s*\{[\s\S]*?\n\}/', $contents, $matches)) {
            $this->fail("accessDetailLabel helper not found in {$relativePath}");
        }

        return $matches[0];
    }

    /**
     * @param  array<string, mixed>  $subscriptionAttributes
     */
    private function makeInstallationWithSubscription(array $subscriptionAttributes = []): Installation
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

        Subscription::query()->create(array_merge([
            'installation_id' => $installation->id,
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => Subscription::STATUS_ACTIVE,
        ], $subscriptionAttributes));

        return $installation;
    }
}
