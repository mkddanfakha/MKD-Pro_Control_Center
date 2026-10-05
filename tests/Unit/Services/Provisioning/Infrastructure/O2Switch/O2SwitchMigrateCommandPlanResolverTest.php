<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure\O2Switch;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateArtisanCommandPolicy;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateCommandPlanResolver;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateDatabaseTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2SwitchMigrateCommandPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_migrate_argv_matches_gestion_policy(): void
    {
        $this->assertSame(
            ['php', 'artisan', 'migrate', '--force'],
            O2SwitchMigrateArtisanCommandPolicy::productionMigrateArgv(),
        );
        $this->assertTrue(
            O2SwitchMigrateArtisanCommandPolicy::assertProductionMigrateArgv(
                O2SwitchMigrateArtisanCommandPolicy::productionMigrateArgv(),
            ),
        );
    }

    public function test_forbidden_destructive_subcommands_are_detected(): void
    {
        $this->assertTrue(O2SwitchMigrateArtisanCommandPolicy::containsForbiddenSubcommand([
            'php', 'artisan', 'migrate:fresh', '--force',
        ]));
        $this->assertTrue(O2SwitchMigrateArtisanCommandPolicy::containsForbiddenSubcommand([
            'php', 'artisan', 'migrate', '--force', 'db:wipe',
        ]));
    }

    public function test_resolver_builds_plan_with_working_directory_and_target(): void
    {
        $configuration = new O2SwitchMigrateConfiguration(
            provider: 'o2switch',
            enabled: true,
            dryRun: true,
            accountLogicalId: 'acct',
            deploymentRootBase: '/home/cpuser',
            cpanelHost: '',
            forbiddenMigrationDatabaseNames: [],
        );

        $context = $this->makeContext();
        $target = new O2SwitchMigrateDatabaseTarget(
            installationId: $context->installationId,
            databaseName: 'gestion_client_db_a',
            databaseHost: 'localhost',
            targetFingerprint: 'fp-test',
        );

        $resolved = O2SwitchMigrateCommandPlanResolver::resolve($context, $configuration, $target);

        $this->assertNotNull($resolved['plan']);
        $this->assertStringContainsString('/home/cpuser', $resolved['plan']->workingDirectory);
        $this->assertSame(['php', 'artisan', 'migrate', '--force'], $resolved['plan']->artisanMigrateArgv);
        $this->assertSame('fp-test', $resolved['plan']->databaseTarget->targetFingerprint);
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'Migrate Plan Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Migrate Plan Installation',
            'subdomain' => 'migrate-plan-'.uniqid(),
            'status' => 'active',
            'database_name' => 'gestion_client_db_a',
            'database_host' => 'localhost',
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
