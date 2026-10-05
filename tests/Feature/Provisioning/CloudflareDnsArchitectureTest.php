<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsAdapter;
use App\Services\Provisioning\Infrastructure\Cloudflare\HttpCloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\Cloudflare\NullCloudflareDnsGateway;
use App\Services\Provisioning\Steps\DnsProvisioningStep;
use Tests\Fakes\Provisioning\Infrastructure\FakeCloudflareDnsGateway;
use Tests\TestCase;

class CloudflareDnsArchitectureTest extends TestCase
{
    public function test_cloudflare_dns_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.cloudflare.dns.enabled'));
        $this->assertFalse(config('provisioning.cloudflare.dns.dry_run'));
    }

    public function test_production_binds_null_gateway_and_cloudflare_adapter(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(NullCloudflareDnsGateway::class, $source);
        $this->assertStringContainsString(CloudflareDnsAdapter::class, $source);
        $this->assertStringNotContainsString(HttpCloudflareDnsGateway::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
        $this->assertStringNotContainsString(FakeCloudflareDnsGateway::class, $source);

        $this->assertInstanceOf(CloudflareDnsAdapter::class, app(DnsRecordAdapter::class));
    }

    public function test_dns_step_does_not_reference_cloudflare_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(DnsProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('Cloudflare', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringContainsString(DnsRecordAdapter::class, $source);
    }

    public function test_adapter_and_null_gateway_contain_no_committed_secrets(): void
    {
        foreach ([
            app_path('Services/Provisioning/Infrastructure/Cloudflare/CloudflareDnsAdapter.php'),
            app_path('Services/Provisioning/Infrastructure/Cloudflare/NullCloudflareDnsGateway.php'),
            app_path('Services/Provisioning/Infrastructure/Cloudflare/CloudflareDnsConfiguration.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('Bearer sk_', $source, basename($path));
            $this->assertStringNotContainsString('password=', $source, basename($path));
        }
    }

    public function test_http_gateway_uses_official_api_base_only_in_gateway_class(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/Infrastructure/Cloudflare/HttpCloudflareDnsGateway.php'));

        $this->assertStringContainsString('https://api.cloudflare.com/client/v4', $source);
        $this->assertStringNotContainsString('api.cloudflare.fake', $source);
    }

    public function test_env_example_documents_cloudflare_flags_without_token_value(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_CLOUDFLARE_DNS_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_CLOUDFLARE_DNS_DRY_RUN=false', $example);
        $this->assertStringNotContainsString('PROVISIONING_CLOUDFLARE_API_TOKEN=cf_', $example);
    }
}
