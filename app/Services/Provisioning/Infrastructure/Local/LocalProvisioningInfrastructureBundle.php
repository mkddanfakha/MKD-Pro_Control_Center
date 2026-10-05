<?php

namespace App\Services\Provisioning\Infrastructure\Local;

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
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * Jeu d'adaptateurs locaux TASK 355 — injection explicite en test uniquement.
 */
final class LocalProvisioningInfrastructureBundle
{
    public readonly LocalControlledInfrastructureState $state;

    public readonly LocalCapacityReservationAdapter $capacityReservation;

    public readonly LocalDnsRecordAdapter $dns;

    public readonly LocalHostingSpaceAdapter $hosting;

    public readonly LocalClientDatabaseAdapter $database;

    public readonly LocalApplicationDeployAdapter $deploy;

    public readonly LocalEnvironmentConfigurationAdapter $environment;

    public readonly LocalDependencyInstallationAdapter $dependencies;

    public readonly LocalApplicationBuildAdapter $build;

    public readonly LocalDatabaseMigrationAdapter $migrate;

    public readonly LocalStorageSetupAdapter $storage;

    public readonly LocalCacheWarmupAdapter $cache;

    public readonly LocalAdminBootstrapAdapter $admin;

    public readonly LocalInstallationModulesAdapter $modules;

    public readonly LocalApplicationHealthAdapter $health;

    public readonly LocalInstallationReadinessPersistenceAdapter $readiness;

    private function __construct()
    {
        $this->state = new LocalControlledInfrastructureState;
        $this->capacityReservation = new LocalCapacityReservationAdapter($this->state);
        $this->dns = new LocalDnsRecordAdapter($this->state);
        $this->hosting = new LocalHostingSpaceAdapter($this->state);
        $this->database = new LocalClientDatabaseAdapter($this->state);
        $this->deploy = new LocalApplicationDeployAdapter($this->state);
        $this->environment = new LocalEnvironmentConfigurationAdapter($this->state);
        $this->dependencies = new LocalDependencyInstallationAdapter($this->state);
        $this->build = new LocalApplicationBuildAdapter($this->state);
        $this->migrate = new LocalDatabaseMigrationAdapter($this->state);
        $this->storage = new LocalStorageSetupAdapter($this->state);
        $this->cache = new LocalCacheWarmupAdapter($this->state);
        $this->admin = new LocalAdminBootstrapAdapter($this->state);
        $this->modules = new LocalInstallationModulesAdapter($this->state);
        $this->health = new LocalApplicationHealthAdapter($this->state);
        $this->readiness = new LocalInstallationReadinessPersistenceAdapter($this->state);
    }

    public static function createFresh(): self
    {
        return new self;
    }

    /**
     * @return array<class-string, object>
     */
    public function containerInstances(): array
    {
        return [
            LocalControlledInfrastructureState::class => $this->state,
            CapacityReservationAdapter::class => $this->capacityReservation,
            DnsRecordAdapter::class => $this->dns,
            HostingSpaceAdapter::class => $this->hosting,
            ClientDatabaseAdapter::class => $this->database,
            ApplicationDeployAdapter::class => $this->deploy,
            EnvironmentConfigurationAdapter::class => $this->environment,
            DependencyInstallationAdapter::class => $this->dependencies,
            ApplicationBuildAdapter::class => $this->build,
            DatabaseMigrationAdapter::class => $this->migrate,
            StorageSetupAdapter::class => $this->storage,
            CacheWarmupAdapter::class => $this->cache,
            AdminBootstrapAdapter::class => $this->admin,
            InstallationModulesAdapter::class => $this->modules,
            ApplicationHealthAdapter::class => $this->health,
            InstallationReadinessPersistenceAdapter::class => $this->readiness,
        ];
    }

    public function registerInApplication(Application $app): void
    {
        foreach ($this->containerInstances() as $abstract => $instance) {
            $app->instance($abstract, $instance);
        }

        $app->forgetInstance(ProvisioningStepRegistry::class);
    }

    /**
     * @return list<string>
     */
    public static function expectedLocalOperationOrder(): array
    {
        return [
            'capacity_reservation',
            'dns',
            'hosting_panel',
            'client_database',
            'application_deploy',
            'environment_configuration',
            'dependency_installation',
            'application_build',
            'database_migrate',
            'storage_link',
            'cache_warmup',
            'admin_user_bootstrap',
            'installation_modules',
            'health_check',
            'readiness:deployed',
            'readiness:verified',
            'readiness:ready',
        ];
    }
}
