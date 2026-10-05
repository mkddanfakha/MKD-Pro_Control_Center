<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsConfiguration;
use App\Services\Provisioning\Infrastructure\Cloudflare\HttpCloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\CpanelUapiO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;
use App\Support\AuditLogAdminPresentation;
use App\Support\ProvisioningAuditPayloadSanitizer;
use App\Services\Provisioning\ProvisioningExecutionAuditService;
use App\Services\Provisioning\ProvisioningPersistedRunOrchestrator;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\ProvisioningRunStateService;
use App\Services\ProvisioningRunStepStateService;
use App\Support\ProvisioningRunAdminPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Provisioning\FakeThrowingStep;
use Tests\TestCase;

class ProvisioningCredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_CPANEL_TOKEN = 'fake-cpanel-token-for-security-test';

    private const FAKE_CLOUDFLARE_TOKEN = 'fake-cloudflare-token-for-security-test';

    public function test_cpanel_gateway_http_403_never_surfaces_api_token_in_adapter_result(): void
    {
        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response(
                ['errors' => ['Authorization: cpanel cpuser:'.self::FAKE_CPANEL_TOKEN]],
                403,
            ),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', self::FAKE_CPANEL_TOKEN);
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');

        $gateway = new CpanelUapiO2SwitchDatabaseGateway;
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: false,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: 'panel.example.test',
        );

        $context = $this->makeContext(['subdomain' => 'sec-test-'.uniqid()]);
        $result = $gateway->provisionDatabase($context, $configuration);

        $encoded = json_encode([
            $result->code,
            $result->operatorMessage,
            $result->outputSummary,
            $result->metadata,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::FAKE_CPANEL_TOKEN, $encoded);
        $this->assertSame('o2switch_database_access_denied', $result->code);
    }

    public function test_cloudflare_gateway_failure_never_surfaces_bearer_token_in_adapter_result(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => []], 401),
        ]);

        config()->set('provisioning.secrets.cloudflare_api_token', self::FAKE_CLOUDFLARE_TOKEN);
        config()->set('provisioning.cloudflare.dns.zone_id', 'zone-id-test');
        config()->set('provisioning.cloudflare.dns.record_content', 'target.example.test');

        $configuration = CloudflareDnsConfiguration::fromApplicationConfig();
        $gateway = new HttpCloudflareDnsGateway;
        $context = $this->makeContext(['subdomain' => 'dns-sec-'.uniqid(), 'domain' => 'client.example.test']);

        $result = $gateway->configureDns($context, $configuration);

        $encoded = json_encode([
            $result->code,
            $result->operatorMessage,
            $result->outputSummary,
            $result->metadata,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::FAKE_CLOUDFLARE_TOKEN, $encoded);
    }

    public function test_audit_payload_sanitizer_redacts_summary_with_embedded_url_credentials(): void
    {
        $sanitized = ProvisioningAuditPayloadSanitizer::sanitize([
            'summary' => 'Fetch failed for https://deploy:secret-pass@git.internal/repo.git',
        ]);

        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-pass', $encoded);
        $this->assertStringNotContainsString('deploy:secret', $encoded);
    }

    public function test_provisioning_run_admin_presentation_redacts_sensitive_output_summary_values(): void
    {
        $json = ProvisioningRunAdminPresentation::safePublicSummary([
            'repo_url' => 'https://git:token@example.com/org/repo.git',
            'status' => 'planned',
        ]);

        $this->assertIsString($json);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringContainsString('[filtré]', $json);
    }

    public function test_audit_log_admin_presentation_does_not_expose_token_keys_from_provisioning_audit(): void
    {
        $log = new AuditLog([
            'action' => 'provisioning.step_failed',
            'new_values' => ProvisioningAuditPayloadSanitizer::sanitize([
                'token' => 'must-not-appear',
                'summary' => 'Échec générique',
            ]),
        ]);

        $serialized = AuditLogAdminPresentation::serializeEntry($log, []);
        $encoded = json_encode($serialized, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('must-not-appear', $encoded);
    }

    public function test_persisted_run_audit_after_throwable_masks_secrets_in_stored_new_values(): void
    {
        $run = $this->createPendingRun();
        $pipeline = $this->pipelineWithSteps([
            new FakeThrowingStep(
                'leak-check',
                1,
                new \RuntimeException('Authorization: Bearer '.self::FAKE_CLOUDFLARE_TOKEN),
            ),
        ]);

        try {
            $pipeline->runPersisted($run);
        } catch (\App\Exceptions\Provisioning\ProvisioningStepExecutionException) {
        }

        $log = AuditLog::query()->where('action', 'provisioning.step_failed')->sole();
        $encoded = json_encode($log->new_values ?? [], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::FAKE_CLOUDFLARE_TOKEN, $encoded);
    }

    /**
     * @param  list<\App\Contracts\Provisioning\ProvisioningStep>  $steps
     */
    private function pipelineWithSteps(array $steps): ProvisioningPipeline
    {
        $registry = new ProvisioningStepRegistry;
        foreach ($steps as $step) {
            $registry->register($step);
        }

        $orchestrator = new ProvisioningPersistedRunOrchestrator(
            $registry,
            app(ProvisioningRunStateService::class),
            app(ProvisioningRunStepStateService::class),
            app(ProvisioningExecutionAuditService::class),
        );

        return new ProvisioningPipeline($registry, $orchestrator);
    }

    private function createPendingRun(): ProvisioningRun
    {
        $client = Client::query()->create([
            'company_name' => 'Credential Security Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Credential Installation',
            'subdomain' => 'cred-'.uniqid(),
            'domain' => 'client.example.test',
            'status' => 'active',
        ]);

        return ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     */
    private function makeContext(array $installationOverrides = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Gateway Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Gateway Installation',
            'subdomain' => 'gw-'.uniqid(),
            'domain' => 'client.example.test',
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
