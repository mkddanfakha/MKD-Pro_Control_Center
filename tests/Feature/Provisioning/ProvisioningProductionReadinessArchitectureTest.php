<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRunStep;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\Cloudflare\HttpCloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\Cloudflare\NullCloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\CpanelUapiO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\NullO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\CpanelGitUapiO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\NullO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\CpanelFileUapiO2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\NullO2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\NullO2SwitchHostingGateway;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableInstallationReadinessPersistenceAdapter;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Invariants de préparation à l'activation réelle — TASK 372.
 */
class ProvisioningProductionReadinessArchitectureTest extends TestCase
{
    public function test_production_feature_flags_disabled_by_default(): void
    {
        $flags = [
            'provisioning.cloudflare.dns.enabled',
            'provisioning.o2switch.hosting.enabled',
            'provisioning.o2switch.database.enabled',
            'provisioning.o2switch.deploy.enabled',
            'provisioning.o2switch.environment.enabled',
            'provisioning.o2switch.dependencies.enabled',
            'provisioning.o2switch.build.enabled',
            'provisioning.o2switch.migrate.enabled',
            'provisioning.o2switch.storage.enabled',
            'provisioning.o2switch.cache.enabled',
            'provisioning.o2switch.admin.enabled',
            'provisioning.o2switch.modules.enabled',
            'provisioning.o2switch.health.enabled',
        ];

        foreach ($flags as $key) {
            $this->assertFalse(
                (bool) config($key),
                'Le flag '.$key.' doit rester désactivé par défaut en environnement de test.',
            );
        }
    }

    public function test_production_provider_binds_null_gateways_and_unavailable_adapters_only(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        foreach ([
            NullCloudflareDnsGateway::class,
            NullO2SwitchHostingGateway::class,
            NullO2SwitchDatabaseGateway::class,
            NullO2SwitchDeployGateway::class,
            NullO2SwitchEnvironmentGateway::class,
            UnavailableCapacityReservationAdapter::class,
            UnavailableInstallationReadinessPersistenceAdapter::class,
        ] as $binding) {
            $this->assertStringContainsString($binding, $source);
        }

        foreach ([
            HttpCloudflareDnsGateway::class,
            CpanelUapiO2SwitchDatabaseGateway::class,
            CpanelGitUapiO2SwitchDeployGateway::class,
            CpanelFileUapiO2SwitchEnvironmentGateway::class,
        ] as $forbiddenBinding) {
            $this->assertStringNotContainsString($forbiddenBinding, $source);
        }

        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);
    }

    public function test_eighteen_step_registry_matches_canonical_order(): void
    {
        $registry = app(ProvisioningStepRegistry::class);
        $keys = array_map(fn ($step) => $step->stepKey(), $registry->orderedExecutableSteps());

        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $keys);
    }

    public function test_provisioning_routes_and_scheduler_unchanged_for_readiness_audit(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning-runs')
                || str_contains((string) $route->getName(), 'installations.provisioning-runs'),
        );
        $this->assertCount(0, $routes);

        $subscriptionTasks = collect(Schedule::events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'subscriptions:'));

        $this->assertCount(3, $subscriptionTasks);

        $provisioningTasks = collect(Schedule::events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'provisioning'));

        $this->assertCount(0, $provisioningTasks);
    }

    public function test_live_http_gateways_exist_but_are_not_default_production_bindings(): void
    {
        $this->assertTrue(class_exists(HttpCloudflareDnsGateway::class));
        $this->assertTrue(class_exists(CpanelUapiO2SwitchDatabaseGateway::class));
        $this->assertTrue(class_exists(CpanelGitUapiO2SwitchDeployGateway::class));
        $this->assertTrue(class_exists(CpanelFileUapiO2SwitchEnvironmentGateway::class));

        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());
        $this->assertStringContainsString(NullO2SwitchDatabaseGateway::class, $source);
        $this->assertStringNotContainsString(CpanelUapiO2SwitchDatabaseGateway::class, $source);
    }
}
