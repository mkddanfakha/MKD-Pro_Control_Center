<?php

namespace Tests\Unit\DTO;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProvisioningContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_run_exposes_expected_non_secret_fields(): void
    {
        $run = $this->makeRun([
            'target_version' => '1.2.0',
            'target_commit' => str_repeat('a', 40),
            'pipeline_version' => 'cc-pipeline-1',
        ]);

        $context = ProvisioningContext::fromRun($run);

        $this->assertSame($run->id, $context->provisioningRun->id);
        $this->assertSame($run->installation_id, $context->installation->id);
        $this->assertSame('1.2.0', $context->targetVersion);
        $this->assertSame(str_repeat('a', 40), $context->targetCommit);
        $this->assertSame('cc-pipeline-1', $context->pipelineVersion);

        $this->assertSame($run->installation->client_id, $context->clientId);
        $this->assertSame($run->installation->name, $context->installationName);
        $this->assertSame($run->installation->subdomain, $context->subdomain);

        $safe = $context->toSafeArray();
        $this->assertArrayHasKey('provisioning_run_id', $safe);
        $this->assertArrayHasKey('client_id', $safe);
        $this->assertArrayHasKey('installation_name', $safe);
        $this->assertArrayHasKey('subdomain', $safe);
        $this->assertArrayNotHasKey('password', $safe);
    }

    public function test_from_run_exposes_database_and_domain_when_present(): void
    {
        $client = Client::query()->create([
            'company_name' => 'Context Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'DB Installation',
            'subdomain' => 'db-'.uniqid(),
            'domain' => 'client.example.test',
            'database_name' => 'mkd_client_test',
            'database_host' => 'mysql.internal.test',
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $context = ProvisioningContext::fromRun($run);

        $this->assertSame('client.example.test', $context->domain);
        $this->assertSame('mkd_client_test', $context->databaseName);
        $this->assertSame('mysql.internal.test', $context->databaseHost);
    }

    public function test_forbidden_secret_keys_in_configuration_are_rejected(): void
    {
        $run = $this->makeRun();

        $this->expectException(InvalidArgumentException::class);

        new ProvisioningContext(
            provisioningRun: $run,
            installation: $run->installation,
            targetVersion: null,
            targetCommit: null,
            pipelineVersion: null,
            configuration: ['api_key' => 'must-not-persist'],
        );
    }

    public function test_is_forbidden_secret_key_detects_common_patterns(): void
    {
        $this->assertTrue(ProvisioningContext::isForbiddenSecretKey('db_password'));
        $this->assertTrue(ProvisioningContext::isForbiddenSecretKey('vault_token'));
        $this->assertFalse(ProvisioningContext::isForbiddenSecretKey('hostname'));
    }

    private function makeRun(array $overrides = []): ProvisioningRun
    {
        $client = Client::query()->create([
            'company_name' => 'Context Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Context Installation',
            'subdomain' => 'ctx-'.uniqid(),
            'status' => 'active',
        ]);

        return ProvisioningRun::query()->create(array_merge([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ], $overrides));
    }
}
