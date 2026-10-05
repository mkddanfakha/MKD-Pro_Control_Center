<?php

namespace Tests\Unit\Services\Provisioning;

use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Services\Provisioning\Infrastructure\Preflight\CloudflareDnsPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchDatabasePreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchFilemanPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchGitPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchSshPreflightCheck;
use App\Services\Provisioning\ProvisioningInfrastructurePreflight;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InfrastructurePreflightTest extends TestCase
{
    private const FAKE_CPANEL_TOKEN = 'fake-cpanel-token-preflight-test';

    private const FAKE_CLOUDFLARE_TOKEN = 'fake-cloudflare-token-preflight-test';

    protected function tearDown(): void
    {
        Http::fake();
        parent::tearDown();
    }

    public function test_cloudflare_not_configured_without_token(): void
    {
        config()->set('provisioning.secrets.cloudflare_api_token', null);

        $result = (new CloudflareDnsPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::NOT_CONFIGURED, $result->state);
    }

    public function test_cloudflare_ready_on_zone_and_dns_list_200(): void
    {
        $this->configureCloudflareForPreflight();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response(['success' => true, 'result' => ['id' => 'zone-test-id']], 200),
            'api.cloudflare.com/client/v4/zones/zone-test-id/dns_records*' => Http::response(['success' => true, 'result' => []], 200),
        ]);

        $result = (new CloudflareDnsPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::READY, $result->state);
        $this->assertTrue($result->configuredAndReachable);
    }

    public function test_cloudflare_authentication_failed_on_401(): void
    {
        $this->configureCloudflareForPreflight();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response([], 401),
        ]);

        $result = (new CloudflareDnsPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::AUTHENTICATION_FAILED, $result->state);
    }

    public function test_cloudflare_forbidden_on_403(): void
    {
        $this->configureCloudflareForPreflight();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response(['success' => true, 'result' => ['id' => 'zone-test-id']], 200),
            'api.cloudflare.com/client/v4/zones/zone-test-id/dns_records*' => Http::response([], 403),
        ]);

        $result = (new CloudflareDnsPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::FORBIDDEN, $result->state);
    }

    public function test_unreachable_is_a_supported_terminal_state_for_timeouts(): void
    {
        $this->assertContains(
            InfrastructurePreflightState::UNREACHABLE,
            InfrastructurePreflightState::terminalStates(),
        );
    }

    public function test_cloudflare_invalid_api_payload_returns_failed(): void
    {
        $this->configureCloudflareForPreflight();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response(['success' => true, 'result' => ['id' => 'zone-test-id']], 200),
            'api.cloudflare.com/client/v4/zones/zone-test-id/dns_records*' => Http::response(['success' => false], 200),
        ]);

        $result = (new CloudflareDnsPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::FAILED, $result->state);
    }

    public function test_cpanel_database_ready_on_list_databases_200(): void
    {
        $this->configureO2SwitchDatabasePreflight();

        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response(['result' => ['data' => []]], 200),
        ]);

        $result = (new O2SwitchDatabasePreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::READY, $result->state);
    }

    public function test_cpanel_database_authentication_failed_on_401(): void
    {
        $this->configureO2SwitchDatabasePreflight();

        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response([], 401),
        ]);

        $result = (new O2SwitchDatabasePreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::AUTHENTICATION_FAILED, $result->state);
    }

    public function test_cpanel_database_forbidden_on_403(): void
    {
        $this->configureO2SwitchDatabasePreflight();

        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response([], 403),
        ]);

        $result = (new O2SwitchDatabasePreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::FORBIDDEN, $result->state);
    }

    public function test_git_protocol_pending_without_probe_root(): void
    {
        $this->configureO2SwitchGitPreflight();
        config()->set('provisioning.preflight.git_probe_root', '');

        $result = (new O2SwitchGitPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::PROTOCOL_PENDING, $result->state);
    }

    public function test_git_read_only_retrieve_success(): void
    {
        $this->configureO2SwitchGitPreflight();
        config()->set('provisioning.preflight.git_probe_root', '/home/cpuser/existing-repo');

        Http::fake([
            '*2083/execute/Git/retrieve*' => Http::response(['result' => ['data' => ['deployed' => true]]], 200),
        ]);

        $result = (new O2SwitchGitPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::READY, $result->state);
    }

    public function test_fileman_protocol_pending_without_probe_file(): void
    {
        $this->configureO2SwitchFilemanPreflight();
        config()->set('provisioning.preflight.fileman_probe_dir', '');
        config()->set('provisioning.preflight.fileman_probe_file', '');

        $result = (new O2SwitchFilemanPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::PROTOCOL_PENDING, $result->state);
    }

    public function test_fileman_read_only_get_file_content_success(): void
    {
        $this->configureO2SwitchFilemanPreflight();
        config()->set('provisioning.preflight.fileman_probe_dir', '/home/cpuser');
        config()->set('provisioning.preflight.fileman_probe_file', '.bashrc');

        Http::fake([
            '*2083/execute/Fileman/get_file_content*' => Http::response(['result' => ['data' => '']], 200),
        ]);

        $result = (new O2SwitchFilemanPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::READY, $result->state);
    }

    public function test_ssh_reports_unsupported_not_required(): void
    {
        $result = (new O2SwitchSshPreflightCheck)->run();

        $this->assertSame(InfrastructurePreflightState::UNSUPPORTED, $result->state);
    }

    public function test_provisioning_feature_can_be_disabled_while_preflight_runs(): void
    {
        $this->configureO2SwitchDatabasePreflight();
        config()->set('provisioning.o2switch.database.enabled', false);

        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response(['result' => ['data' => []]], 200),
        ]);

        $result = (new O2SwitchDatabasePreflightCheck)->run();

        $this->assertFalse($result->provisioningFeatureEnabled);
        $this->assertSame(InfrastructurePreflightState::READY, $result->state);
        $this->assertTrue($result->configuredAndReachable);
    }

    public function test_global_not_ready_when_mandatory_git_is_protocol_pending(): void
    {
        $this->configureAllMandatoryReadyExceptGitProbe();

        $report = app(ProvisioningInfrastructurePreflight::class)->run();

        $this->assertSame(InfrastructurePreflightState::GLOBAL_NOT_READY, $report->globalState);
    }

    public function test_global_ready_when_all_mandatory_checks_are_ready(): void
    {
        $this->configureAllMandatoryReady();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response(['success' => true, 'result' => ['id' => 'zone-test-id']], 200),
            'api.cloudflare.com/client/v4/zones/zone-test-id/dns_records*' => Http::response(['success' => true, 'result' => []], 200),
            '*2083/execute/Mysql/list_databases*' => Http::response(['result' => ['data' => []]], 200),
            '*2083/execute/Git/retrieve*' => Http::response(['result' => ['data' => []]], 200),
            '*2083/execute/Fileman/get_file_content*' => Http::response(['result' => ['data' => '']], 200),
        ]);

        $report = app(ProvisioningInfrastructurePreflight::class)->run();

        $this->assertSame(InfrastructurePreflightState::GLOBAL_READY, $report->globalState);
    }

    public function test_report_json_never_contains_configured_secrets(): void
    {
        $this->configureAllMandatoryReady();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response(['success' => true, 'result' => ['id' => 'zone-test-id']], 200),
            'api.cloudflare.com/client/v4/zones/zone-test-id/dns_records*' => Http::response(['success' => true, 'result' => []], 200),
            '*2083/execute/Mysql/list_databases*' => Http::response(['result' => ['data' => []]], 200),
            '*2083/execute/Git/retrieve*' => Http::response(['result' => ['data' => []]], 200),
            '*2083/execute/Fileman/get_file_content*' => Http::response(['result' => ['data' => '']], 200),
        ]);

        $encoded = json_encode(app(ProvisioningInfrastructurePreflight::class)->run()->toPublicArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::FAKE_CPANEL_TOKEN, $encoded);
        $this->assertStringNotContainsString(self::FAKE_CLOUDFLARE_TOKEN, $encoded);
        $this->assertStringNotContainsString('Authorization', $encoded);
    }

    private function configureCloudflareForPreflight(): void
    {
        config()->set('provisioning.secrets.cloudflare_api_token', self::FAKE_CLOUDFLARE_TOKEN);
        config()->set('provisioning.cloudflare.dns.enabled', false);
        config()->set('provisioning.cloudflare.dns.zone_id', 'zone-test-id');
        config()->set('provisioning.cloudflare.dns.zone_name', '');
    }

    private function configureO2SwitchDatabasePreflight(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', self::FAKE_CPANEL_TOKEN);
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        config()->set('provisioning.o2switch.database.enabled', true);
        config()->set('provisioning.o2switch.database.cpanel_host', 'panel.example.test');
    }

    private function configureO2SwitchGitPreflight(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', self::FAKE_CPANEL_TOKEN);
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        config()->set('provisioning.o2switch.deploy.enabled', false);
        config()->set('provisioning.o2switch.deploy.cpanel_host', 'panel.example.test');
    }

    private function configureO2SwitchFilemanPreflight(): void
    {
        config()->set('provisioning.secrets.o2switch_api_token', self::FAKE_CPANEL_TOKEN);
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        config()->set('provisioning.o2switch.environment.enabled', false);
        config()->set('provisioning.o2switch.environment.cpanel_host', 'panel.example.test');
    }

    private function configureAllMandatoryReadyExceptGitProbe(): void
    {
        $this->configureCloudflareForPreflight();
        $this->configureO2SwitchDatabasePreflight();
        $this->configureO2SwitchGitPreflight();
        $this->configureO2SwitchFilemanPreflight();
        config()->set('provisioning.preflight.git_probe_root', '');
        config()->set('provisioning.preflight.fileman_probe_dir', '/home/cpuser');
        config()->set('provisioning.preflight.fileman_probe_file', '.bashrc');

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-test-id' => Http::response(['success' => true, 'result' => ['id' => 'zone-test-id']], 200),
            'api.cloudflare.com/client/v4/zones/zone-test-id/dns_records*' => Http::response(['success' => true, 'result' => []], 200),
            '*2083/execute/Mysql/list_databases*' => Http::response(['result' => ['data' => []]], 200),
            '*2083/execute/Fileman/get_file_content*' => Http::response(['result' => ['data' => '']], 200),
        ]);
    }

    private function configureAllMandatoryReady(): void
    {
        $this->configureCloudflareForPreflight();
        $this->configureO2SwitchDatabasePreflight();
        $this->configureO2SwitchGitPreflight();
        $this->configureO2SwitchFilemanPreflight();
        config()->set('provisioning.preflight.git_probe_root', '/home/cpuser/repo');
        config()->set('provisioning.preflight.fileman_probe_dir', '/home/cpuser');
        config()->set('provisioning.preflight.fileman_probe_file', '.bashrc');
    }
}
