<?php

namespace App\Providers;

use App\Contracts\Provisioning\Infrastructure\AdminBootstrapAdapter;
use App\Contracts\Provisioning\Infrastructure\ApplicationBuildAdapter;
use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\Contracts\Provisioning\Infrastructure\ApplicationHealthAdapter;
use App\Contracts\Provisioning\Infrastructure\CacheWarmupAdapter;
use App\Contracts\Provisioning\Infrastructure\CapacityReservationAdapter;
use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\Contracts\Provisioning\Infrastructure\DatabaseMigrationAdapter;
use App\Contracts\Provisioning\Infrastructure\DependencyInstallationAdapter;
use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\Contracts\Provisioning\Infrastructure\EnvironmentConfigurationAdapter;
use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\Contracts\Provisioning\Infrastructure\InstallationModulesAdapter;
use App\Contracts\Provisioning\Infrastructure\InstallationReadinessPersistenceAdapter;
use App\Contracts\Provisioning\Infrastructure\StorageSetupAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableAdminBootstrapAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableApplicationBuildAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableApplicationDeployAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableApplicationHealthAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCacheWarmupAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableClientDatabaseAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableDatabaseMigrationAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableDependencyInstallationAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableDnsRecordAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableEnvironmentConfigurationAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableHostingSpaceAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableInstallationModulesAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableInstallationReadinessPersistenceAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableStorageSetupAdapter;
use App\Services\Provisioning\ProductionProvisioningStepCatalog;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Socle métier provisioning — adaptateurs indisponibles par défaut (aucun appel infrastructure réel).
 */
class ProvisioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerUnavailableInfrastructureBindings();

        $this->app->singleton(ProvisioningStepRegistry::class, function (): ProvisioningStepRegistry {
            $registry = new ProvisioningStepRegistry;
            ProductionProvisioningStepCatalog::registerProductionSteps($registry);

            return $registry;
        });
    }

    private function registerUnavailableInfrastructureBindings(): void
    {
        $this->app->bind(CapacityReservationAdapter::class, UnavailableCapacityReservationAdapter::class);
        $this->app->bind(DnsRecordAdapter::class, UnavailableDnsRecordAdapter::class);
        $this->app->bind(HostingSpaceAdapter::class, UnavailableHostingSpaceAdapter::class);
        $this->app->bind(ClientDatabaseAdapter::class, UnavailableClientDatabaseAdapter::class);
        $this->app->bind(ApplicationDeployAdapter::class, UnavailableApplicationDeployAdapter::class);
        $this->app->bind(EnvironmentConfigurationAdapter::class, UnavailableEnvironmentConfigurationAdapter::class);
        $this->app->bind(DependencyInstallationAdapter::class, UnavailableDependencyInstallationAdapter::class);
        $this->app->bind(ApplicationBuildAdapter::class, UnavailableApplicationBuildAdapter::class);
        $this->app->bind(DatabaseMigrationAdapter::class, UnavailableDatabaseMigrationAdapter::class);
        $this->app->bind(StorageSetupAdapter::class, UnavailableStorageSetupAdapter::class);
        $this->app->bind(CacheWarmupAdapter::class, UnavailableCacheWarmupAdapter::class);
        $this->app->bind(AdminBootstrapAdapter::class, UnavailableAdminBootstrapAdapter::class);
        $this->app->bind(InstallationModulesAdapter::class, UnavailableInstallationModulesAdapter::class);
        $this->app->bind(ApplicationHealthAdapter::class, UnavailableApplicationHealthAdapter::class);
        $this->app->bind(
            InstallationReadinessPersistenceAdapter::class,
            UnavailableInstallationReadinessPersistenceAdapter::class,
        );
    }
}
