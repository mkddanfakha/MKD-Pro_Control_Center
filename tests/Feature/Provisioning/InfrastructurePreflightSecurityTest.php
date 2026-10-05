<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class InfrastructurePreflightSecurityTest extends TestCase
{
    public function test_preflight_check_result_rejects_sensitive_operator_messages(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InfrastructurePreflightCheckResult(
            serviceKey: 'test',
            serviceLabel: 'Test',
            capability: 'test',
            state: InfrastructurePreflightState::FAILED,
            code: 'test',
            operatorMessage: 'Authorization: Bearer super-secret-token-value',
            provisioningFeatureEnabled: false,
            configuredAndReachable: false,
        );
    }

    public function test_preflight_diagnostics_are_sanitized_in_public_array(): void
    {
        $result = new InfrastructurePreflightCheckResult(
            serviceKey: 'test',
            serviceLabel: 'Test',
            capability: 'test',
            state: InfrastructurePreflightState::READY,
            code: 'test_ready',
            operatorMessage: 'OK',
            provisioningFeatureEnabled: false,
            configuredAndReachable: true,
            diagnostics: ['note' => 'https://user:pass@host.example.test/path'],
        );

        $encoded = json_encode($result->toPublicArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('user:pass', $encoded);
    }

    public function test_cpanel_gateway_error_body_does_not_leak_into_database_preflight_result(): void
    {
        Http::fake([
            '*2083/execute/Mysql/list_databases*' => Http::response(
                ['errors' => ['Authorization: cpanel cpuser:leaked-cpanel-token-value']],
                403,
            ),
        ]);

        config()->set('provisioning.secrets.o2switch_api_token', 'leaked-cpanel-token-value');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpuser');
        config()->set('provisioning.o2switch.database.cpanel_host', 'panel.example.test');

        $check = new \App\Services\Provisioning\Infrastructure\Preflight\O2SwitchDatabasePreflightCheck;
        $result = $check->run();
        $encoded = json_encode($result->toPublicArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('leaked-cpanel-token-value', $encoded);
        $this->assertSame(InfrastructurePreflightState::FORBIDDEN, $result->state);
    }

    public function test_sanitizer_used_by_preflight_result_matches_provisioning_policy(): void
    {
        $this->assertTrue(
            ProvisioningSecretSanitizer::stringContainsSensitiveExposure('Authorization: cpanel user:token'),
        );
    }
}
