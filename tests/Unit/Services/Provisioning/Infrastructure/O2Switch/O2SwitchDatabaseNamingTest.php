<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseNaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2SwitchDatabaseNamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_derives_deterministic_name_from_subdomain_and_installation(): void
    {
        $context = $this->makeContext(['subdomain' => 'Client-X']);
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: '',
        );

        $naming = O2SwitchDatabaseNaming::resolve($context, $configuration);

        $this->assertNotNull($naming);
        $this->assertSame(
            'cpuser_gest_client_x_'.$context->installationId,
            $naming->fullDatabaseName,
        );
        $this->assertLessThanOrEqual(
            O2SwitchDatabaseNaming::MYSQL_IDENTIFIER_MAX_BYTES,
            strlen($naming->fullDatabaseName),
        );
    }

    public function test_uses_explicit_database_name_when_prefix_matches(): void
    {
        $context = $this->makeContext([
            'subdomain' => 'ignored',
            'database_name' => 'cpuser_custom_db',
        ]);
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: '',
        );

        $naming = O2SwitchDatabaseNaming::resolve($context, $configuration);

        $this->assertNotNull($naming);
        $this->assertSame('cpuser_custom_db', $naming->fullDatabaseName);
        $this->assertSame('custom_db', $naming->cpanelCreateSuffix);
    }

    public function test_rejects_database_name_without_required_prefix(): void
    {
        $context = $this->makeContext(['database_name' => 'other_custom_db']);
        $configuration = new O2SwitchDatabaseConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            databaseNamePrefix: 'cpuser_',
            mysqlHostLogical: 'localhost',
            cpanelHost: '',
        );

        $this->assertNull(O2SwitchDatabaseNaming::resolve($context, $configuration));
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     */
    private function makeContext(array $installationOverrides = []): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Naming Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Naming Installation',
            'subdomain' => 'naming-'.uniqid(),
            'status' => 'active',
        ], $installationOverrides));

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);

        return ProvisioningContext::fromRun($run);
    }
}
