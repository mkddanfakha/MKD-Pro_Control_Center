<?php

namespace Tests\Unit\Services\Provisioning;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use InvalidArgumentException;
use Tests\TestCase;

class ProvisioningSecretSanitizationTest extends TestCase
{
    public function test_assert_safe_adapter_operator_message_rejects_bearer_fragments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage('HTTP error: Bearer abcdef123456');
    }

    public function test_assert_safe_adapter_operator_message_rejects_cpanel_authorization_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProvisioningSecretSanitizer::assertSafeAdapterOperatorMessage('Authorization: cpanel user:sekret-token-value');
    }

    public function test_safe_operator_message_from_throwable_masks_password_assignment(): void
    {
        $safe = ProvisioningSecretSanitizer::safeOperatorMessageFromThrowable(
            'Connection failed password=super-secret-value',
            'fallback',
        );

        $this->assertStringNotContainsString('super-secret-value', $safe);
        $this->assertStringContainsString('masqués', $safe);
    }

    public function test_string_contains_sensitive_exposure_detects_url_embedded_credentials(): void
    {
        $this->assertTrue(
            ProvisioningSecretSanitizer::stringContainsSensitiveExposure('https://admin:Pa$$w0rd@panel.example.test/path'),
        );
    }

    public function test_redact_string_for_exposure_fully_redacts_strings_with_url_embedded_credentials(): void
    {
        $redacted = ProvisioningSecretSanitizer::redactStringForExposure(
            'See https://user:pass@host.example.test/docs',
        );

        $this->assertSame('[filtré]', $redacted);
    }

    public function test_sanitize_array_for_exposure_filters_forbidden_keys_and_sensitive_strings(): void
    {
        $sanitized = ProvisioningSecretSanitizer::sanitizeArrayForExposure([
            'token' => 'visible-value',
            'note' => 'Authorization: Bearer xyz',
            'safe' => 'ok',
            'nested' => ['api_key' => 'hidden'],
        ]);

        $this->assertSame('[filtré]', $sanitized['token']);
        $this->assertSame('[filtré]', $sanitized['note']);
        $this->assertSame('ok', $sanitized['safe']);
        $this->assertSame('[filtré]', $sanitized['nested']['api_key']);
    }

    public function test_infrastructure_adapter_result_rejects_operator_message_with_secrets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        InfrastructureAdapterResult::failed(
            'code',
            'Header was Authorization: cpanel cpuser:leaked-token-value',
            retryable: false,
            category: ProvisioningErrorCategory::Definitive,
        );
    }
}
