<?php

namespace Tests\Feature\Provisioning;

use Tests\TestCase;

class InfrastructurePreflightConfigStatusCommandTest extends TestCase
{
    public function test_config_status_command_never_prints_secret_values(): void
    {
        config()->set('provisioning.secrets.cloudflare_api_token', 'super-secret-cloudflare-token');
        config()->set('provisioning.secrets.o2switch_api_token', 'super-secret-cpanel-token');
        config()->set('provisioning.secrets.o2switch_cpanel_username', 'cpanel-user-secret');

        $pending = $this->artisan('provisioning:preflight-config-status');
        $pending->assertSuccessful();
        $pending->expectsOutputToContain('PROVISIONING_CLOUDFLARE_API_TOKEN=CONFIGURED');
        $pending->doesntExpectOutputToContain('super-secret-cloudflare-token');
        $pending->doesntExpectOutputToContain('super-secret-cpanel-token');
        $pending->doesntExpectOutputToContain('cpanel-user-secret');
        $pending->doesntExpectOutputToContain('Authorization');
        $pending->run();
    }

    public function test_config_status_reports_missing_when_env_empty(): void
    {
        config()->set('provisioning.secrets.cloudflare_api_token', null);

        $pending = $this->artisan('provisioning:preflight-config-status');
        $pending->assertSuccessful();
        $pending->expectsOutputToContain('PROVISIONING_CLOUDFLARE_API_TOKEN=MISSING');
        $pending->run();
    }
}
