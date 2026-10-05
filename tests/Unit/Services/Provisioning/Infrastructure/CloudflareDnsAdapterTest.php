<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\Cloudflare\CloudflareDnsGateway;
use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsAdapter;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsConfiguration;
use App\Services\Provisioning\Infrastructure\Cloudflare\HttpCloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\Cloudflare\NullCloudflareDnsGateway;
use App\Services\Provisioning\Steps\DnsProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\Infrastructure\FakeCloudflareDnsGateway;
use Tests\TestCase;

class CloudflareDnsAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.cloudflare.dns', [
            'provider' => 'cloudflare',
            'enabled' => false,
            'dry_run' => false,
            'zone_name' => '',
            'zone_id' => '',
            'record_type' => 'CNAME',
            'record_content' => '',
        ]);
        config()->set('provisioning.secrets.cloudflare_api_token', null);

        parent::tearDown();
    }

    public function test_feature_flag_disabled_returns_manual_intervention_without_network(): void
    {
        Http::fake();

        $result = app(DnsRecordAdapter::class)->configureDns($this->makeContext());

        Http::assertNothingSent();
        $this->assertInstanceOf(CloudflareDnsAdapter::class, app(DnsRecordAdapter::class));
        $this->assertSame('cloudflare_dns_disabled', $result->code);
    }

    public function test_enabled_without_zone_or_token_is_not_configured(): void
    {
        $adapter = new CloudflareDnsAdapter(
            new NullCloudflareDnsGateway,
            new CloudflareDnsConfiguration(
                provider: 'cloudflare',
                enabled: true,
                dryRun: false,
                zoneName: '',
                zoneId: '',
                recordType: 'CNAME',
            ),
        );

        config()->set('provisioning.secrets.cloudflare_api_token', null);

        $result = $adapter->configureDns($this->makeContext());

        $this->assertSame('cloudflare_dns_not_configured', $result->code);
    }

    public function test_dry_run_succeeds_with_simulated_metadata(): void
    {
        Http::fake();

        $adapter = new CloudflareDnsAdapter(
            new NullCloudflareDnsGateway,
            new CloudflareDnsConfiguration(
                provider: 'cloudflare',
                enabled: true,
                dryRun: true,
                zoneName: 'mkd-pro.com',
                zoneId: '',
                recordType: 'CNAME',
            ),
        );

        $context = $this->makeContext(['subdomain' => 'clientx']);
        $result = $adapter->configureDns($context);

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertSame('clientx.mkd-pro.com', $result->outputSummary['record_name'] ?? null);
    }

    public function test_live_mode_with_null_gateway_returns_protocol_pending(): void
    {
        config()->set('provisioning.secrets.cloudflare_api_token', 'test-token-not-real');

        $adapter = new CloudflareDnsAdapter(
            new NullCloudflareDnsGateway,
            new CloudflareDnsConfiguration(
                provider: 'cloudflare',
                enabled: true,
                dryRun: false,
                zoneName: 'mkd-pro.com',
                zoneId: '',
                recordType: 'CNAME',
            ),
        );

        $result = $adapter->configureDns($this->makeContext());

        $this->assertSame('cloudflare_dns_protocol_pending', $result->code);
    }

    public function test_http_gateway_zone_not_found_without_real_network(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => []], 200),
        ]);

        config()->set('provisioning.secrets.cloudflare_api_token', 'fake-token-for-test');

        $gateway = new HttpCloudflareDnsGateway;
        $configuration = new CloudflareDnsConfiguration(
            provider: 'cloudflare',
            enabled: true,
            dryRun: false,
            zoneName: 'mkd-pro.com',
            zoneId: '',
            recordType: 'CNAME',
        );

        $result = $gateway->configureDns($this->makeContext(['subdomain' => 'clientx']), $configuration);

        $this->assertSame('cloudflare_dns_zone_not_found', $result->code);
        Http::assertSentCount(1);
    }

    public function test_http_gateway_existing_record_is_idempotent(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-123/dns_records*' => Http::response([
                'success' => true,
                'result' => [
                    ['type' => 'CNAME', 'name' => 'clientx.mkd-pro.com', 'content' => 'target.example.test'],
                ],
            ], 200),
        ]);

        config()->set('provisioning.secrets.cloudflare_api_token', 'fake-token-for-test');
        config()->set('provisioning.cloudflare.dns.record_content', 'target.example.test');

        $gateway = new HttpCloudflareDnsGateway;
        $configuration = new CloudflareDnsConfiguration(
            provider: 'cloudflare',
            enabled: true,
            dryRun: false,
            zoneName: 'mkd-pro.com',
            zoneId: 'zone-123',
            recordType: 'CNAME',
        );

        $result = $gateway->configureDns($this->makeContext(['subdomain' => 'clientx']), $configuration);

        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertTrue($result->outputSummary['idempotent_replay'] ?? false);
        Http::assertSentCount(1);
    }

    public function test_http_gateway_detects_conflicting_existing_record(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-123/dns_records*' => Http::response([
                'success' => true,
                'result' => [
                    ['type' => 'CNAME', 'name' => 'clientx.mkd-pro.com', 'content' => 'other.example.test'],
                ],
            ], 200),
        ]);

        config()->set('provisioning.secrets.cloudflare_api_token', 'fake-token-for-test');
        config()->set('provisioning.cloudflare.dns.record_content', 'expected.example.test');

        $gateway = new HttpCloudflareDnsGateway;
        $configuration = new CloudflareDnsConfiguration(
            provider: 'cloudflare',
            enabled: true,
            dryRun: false,
            zoneName: 'mkd-pro.com',
            zoneId: 'zone-123',
            recordType: 'CNAME',
        );

        $result = $gateway->configureDns($this->makeContext(['subdomain' => 'clientx']), $configuration);

        $this->assertSame('cloudflare_dns_conflict', $result->code);
    }

    public function test_http_gateway_create_pending_when_content_missing(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-123/dns_records*' => Http::response([
                'success' => true,
                'result' => [],
            ], 200),
        ]);

        config()->set('provisioning.secrets.cloudflare_api_token', 'fake-token-for-test');
        config()->set('provisioning.cloudflare.dns.record_content', '');

        $gateway = new HttpCloudflareDnsGateway;
        $configuration = new CloudflareDnsConfiguration(
            provider: 'cloudflare',
            enabled: true,
            dryRun: false,
            zoneName: 'mkd-pro.com',
            zoneId: 'zone-123',
            recordType: 'CNAME',
        );

        $result = $gateway->configureDns($this->makeContext(['subdomain' => 'clientx']), $configuration);

        $this->assertSame('cloudflare_dns_record_content_pending', $result->code);
        Http::assertSentCount(1);
    }

    public function test_fake_gateway_error_propagates_through_adapter(): void
    {
        $fake = new FakeCloudflareDnsGateway;
        $fake->nextResult = InfrastructureAdapterResult::failed(
            'cloudflare_dns_gateway_error',
            'Erreur simulée.',
            retryable: true,
            category: \App\DTO\Provisioning\ProvisioningErrorCategory::Retryable,
        );

        $this->app->instance(CloudflareDnsGateway::class, $fake);
        $this->app->instance(DnsRecordAdapter::class, new CloudflareDnsAdapter(
            $fake,
            new CloudflareDnsConfiguration(
                provider: 'cloudflare',
                enabled: true,
                dryRun: false,
                zoneName: 'mkd-pro.com',
                zoneId: 'zone-123',
                recordType: 'CNAME',
            ),
        ));
        config()->set('provisioning.secrets.cloudflare_api_token', 'fake-token-for-test');
        $this->app->forgetInstance(DnsProvisioningStep::class);

        $result = app(DnsProvisioningStep::class)->execute($this->makeContext());

        $this->assertSame('cloudflare_dns_gateway_error', $result->code);
        $this->assertCount(1, $fake->calls);
    }

    public function test_results_never_expose_api_token(): void
    {
        config()->set('provisioning.secrets.cloudflare_api_token', 'super-secret-cloudflare-token-value');

        $adapter = new CloudflareDnsAdapter(new NullCloudflareDnsGateway);
        $result = $adapter->configureDns($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata, $result->operatorMessage]);
        $this->assertStringNotContainsString('super-secret-cloudflare-token-value', (string) $encoded);
        $this->assertStringNotContainsString('api_token', strtolower((string) $encoded));
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     */
    private function makeContext(array $installationOverrides = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Cloudflare DNS Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'DNS Installation',
            'subdomain' => 'dns-'.uniqid(),
            'status' => 'active',
        ], $installationOverrides));

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);
        $installation->setRelation('client', $client);

        return ProvisioningContext::fromRun($run);
    }
}
