<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateDatabaseTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2SwitchMigrateDatabaseTargetResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_target_from_installation_fields_only(): void
    {
        $context = $this->makeContext('db_installation_only', 'localhost');

        $resolved = O2SwitchMigrateDatabaseTargetResolver::resolve($context, []);

        $this->assertNotNull($resolved['target']);
        $this->assertSame($context->installationId, $resolved['target']->installationId);
        $this->assertSame('db_installation_only', $resolved['target']->databaseName);
        $this->assertSame('localhost', $resolved['target']->databaseHost);
        $this->assertNotSame('', $resolved['target']->targetFingerprint);
    }

    public function test_rejects_forbidden_control_center_database_name(): void
    {
        $context = $this->makeContext('mkd_control_center_db', 'localhost');

        $resolved = O2SwitchMigrateDatabaseTargetResolver::resolve($context, ['mkd_control_center_db']);

        $this->assertNull($resolved['target']);
        $this->assertSame('o2switch_migrate_database_target_invalid', $resolved['code']);
    }

    private function makeContext(string $databaseName, string $databaseHost): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Target Resolver Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Target Resolver Installation',
            'subdomain' => 'target-'.uniqid(),
            'status' => 'active',
            'database_name' => $databaseName,
            'database_host' => $databaseHost,
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);

        return ProvisioningContext::fromRun($run);
    }
}
