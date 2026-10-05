<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchGestionEnvBuilder;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchGestionEnvSpecification;
use Tests\TestCase;

class O2SwitchGestionEnvBuilderTest extends TestCase
{
    public function test_allows_only_whitelisted_keys(): void
    {
        $builder = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_ENV' => 'production',
            'APP_URL' => 'https://clientx.example.test',
        ]);

        $this->assertSame(['APP_ENV', 'APP_URL'], $builder->appliedKeyNames());
    }

    public function test_rejects_non_whitelisted_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        O2SwitchGestionEnvBuilder::fromVariables([
            'STRIPE_SECRET' => 'hidden',
        ]);
    }

    public function test_redacts_sensitive_values_in_public_preview(): void
    {
        $builder = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_KEY' => 'base64:real-looking-key-material',
            'DB_PASSWORD' => 'super-db-secret',
            'APP_URL' => 'https://clientx.example.test',
        ]);

        $preview = $builder->publicPreview();
        $this->assertSame('[redacted]', $preview['APP_KEY']);
        $this->assertSame('[redacted]', $preview['DB_PASSWORD']);
        $this->assertSame('https://clientx.example.test', $preview['APP_URL']);
    }

    public function test_fingerprint_is_deterministic_and_hides_secrets(): void
    {
        $builderA = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_URL' => 'https://clientx.example.test',
            'DB_PASSWORD' => 'one-secret',
        ]);
        $builderB = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_URL' => 'https://clientx.example.test',
            'DB_PASSWORD' => 'another-secret',
        ]);

        $this->assertSame($builderA->nonSensitiveFingerprint(), $builderB->nonSensitiveFingerprint());
    }

    public function test_render_does_not_duplicate_keys(): void
    {
        $builder = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'mysql',
        ]);

        $content = $builder->render();
        $this->assertSame(1, substr_count($content, 'APP_ENV='));
        $this->assertStringContainsString('DB_CONNECTION=mysql', $content);
    }

    public function test_pending_sentinels_are_supported_without_leaking_in_preview(): void
    {
        $builder = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_KEY' => O2SwitchGestionEnvSpecification::APP_KEY_PENDING_SENTINEL,
            'DB_PASSWORD' => O2SwitchGestionEnvSpecification::DB_PASSWORD_PENDING_SENTINEL,
            'APP_URL' => 'https://clientx.example.test',
        ]);

        $encoded = json_encode($builder->publicPreview());
        $this->assertStringNotContainsString(O2SwitchGestionEnvSpecification::DB_PASSWORD_PENDING_SENTINEL, (string) $encoded);
        $this->assertSame('[redacted]', $builder->publicPreview()['APP_KEY']);
    }
}
