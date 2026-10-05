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
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchAdminGateway;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHealthGateway;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchModulesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\NullO2SwitchAdminGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\NullO2SwitchModulesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\NullO2SwitchHealthGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Health\O2SwitchHealthAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\Modules\O2SwitchModulesAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchBuildGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\NullO2SwitchBuildGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Build\O2SwitchBuildAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\NullO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchCacheGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\NullO2SwitchCacheGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableCapacityReservationAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\NullO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchMigrateGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\NullO2SwitchMigrateGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Migrate\O2SwitchMigrateAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDependenciesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\NullO2SwitchDependenciesGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Dependencies\O2SwitchDependenciesAdapter;
use App\Contracts\Provisioning\Infrastructure\Cloudflare\CloudflareDnsGateway;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsAdapter;
use App\Services\Provisioning\Infrastructure\Cloudflare\NullCloudflareDnsGateway;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\NullO2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHostingGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\NullO2SwitchHostingGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\O2SwitchHostingAdapter;
use App\Services\Provisioning\Infrastructure\Unavailable\UnavailableInstallationReadinessPersistenceAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchStorageGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\NullO2SwitchStorageGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageAdapter;
use App\Services\Provisioning\Infrastructure\Preflight\CloudflareDnsPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchDatabasePreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchFilemanPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchGitPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchSshPreflightCheck;
use App\Services\Provisioning\ProductionProvisioningStepCatalog;
use App\Services\Provisioning\ProvisioningInfrastructurePreflight;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\Provisioning\Readiness\InstallationReadinessEvaluationService;
use App\Services\Provisioning\Readiness\Verifiers\CapacityReservationReadinessVerifier;
use Illuminate\Support\ServiceProvider;

class ProvisioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerInfrastructureAdapterBindings();
        $this->registerInfrastructurePreflight();
        $this->registerReadinessServices();

        $this->app->singleton(ProvisioningStepRegistry::class, function (): ProvisioningStepRegistry {
            $registry = new ProvisioningStepRegistry;
            ProductionProvisioningStepCatalog::registerProductionSteps($registry);

            return $registry;
        });
    }

    private function registerInfrastructurePreflight(): void
    {
        $this->app->singleton(ProvisioningInfrastructurePreflight::class, function (): ProvisioningInfrastructurePreflight {
            return new ProvisioningInfrastructurePreflight([
                new CloudflareDnsPreflightCheck,
                new O2SwitchDatabasePreflightCheck,
                new O2SwitchGitPreflightCheck,
                new O2SwitchFilemanPreflightCheck,
                new O2SwitchSshPreflightCheck,
            ]);
        });
    }

    private function registerReadinessServices(): void
    {
        $this->app->singleton(CapacityReservationReadinessVerifier::class);
        $this->app->singleton(InstallationReadinessEvaluationService::class);
    }

    private function registerInfrastructureAdapterBindings(): void
    {
        $this->app->bind(CapacityReservationAdapter::class, UnavailableCapacityReservationAdapter::class);
        $this->app->bind(CloudflareDnsGateway::class, NullCloudflareDnsGateway::class);
        $this->app->bind(DnsRecordAdapter::class, CloudflareDnsAdapter::class);
        $this->app->bind(O2SwitchHostingGateway::class, NullO2SwitchHostingGateway::class);
        $this->app->bind(HostingSpaceAdapter::class, O2SwitchHostingAdapter::class);
        $this->app->bind(O2SwitchDatabaseGateway::class, NullO2SwitchDatabaseGateway::class);
        $this->app->bind(ClientDatabaseAdapter::class, O2SwitchDatabaseAdapter::class);
        $this->app->bind(O2SwitchDeployGateway::class, NullO2SwitchDeployGateway::class);
        $this->app->bind(ApplicationDeployAdapter::class, O2SwitchDeployAdapter::class);
        $this->app->bind(O2SwitchEnvironmentGateway::class, NullO2SwitchEnvironmentGateway::class);
        $this->app->bind(EnvironmentConfigurationAdapter::class, O2SwitchEnvironmentAdapter::class);
        $this->app->bind(O2SwitchDependenciesGateway::class, NullO2SwitchDependenciesGateway::class);
        $this->app->bind(DependencyInstallationAdapter::class, O2SwitchDependenciesAdapter::class);
        $this->app->bind(O2SwitchBuildGateway::class, NullO2SwitchBuildGateway::class);
        $this->app->bind(ApplicationBuildAdapter::class, O2SwitchBuildAdapter::class);
        $this->app->bind(O2SwitchMigrateGateway::class, NullO2SwitchMigrateGateway::class);
        $this->app->bind(DatabaseMigrationAdapter::class, O2SwitchMigrateAdapter::class);
        $this->app->bind(O2SwitchStorageGateway::class, NullO2SwitchStorageGateway::class);
        $this->app->bind(StorageSetupAdapter::class, O2SwitchStorageAdapter::class);
        $this->app->bind(O2SwitchCacheGateway::class, NullO2SwitchCacheGateway::class);
        $this->app->bind(CacheWarmupAdapter::class, O2SwitchCacheAdapter::class);
        $this->app->bind(O2SwitchAdminGateway::class, NullO2SwitchAdminGateway::class);
        $this->app->bind(AdminBootstrapAdapter::class, O2SwitchAdminAdapter::class);
        $this->app->bind(O2SwitchModulesGateway::class, NullO2SwitchModulesGateway::class);
        $this->app->bind(InstallationModulesAdapter::class, O2SwitchModulesAdapter::class);
        $this->app->bind(O2SwitchHealthGateway::class, NullO2SwitchHealthGateway::class);
        $this->app->bind(ApplicationHealthAdapter::class, O2SwitchHealthAdapter::class);
        $this->app->bind(
            InstallationReadinessPersistenceAdapter::class,
            UnavailableInstallationReadinessPersistenceAdapter::class,
        );
    }
}
