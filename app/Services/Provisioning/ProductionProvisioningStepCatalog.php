<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\Services\Provisioning\Steps\AdminProvisioningStep;
use App\Services\Provisioning\Steps\BuildProvisioningStep;
use App\Services\Provisioning\Steps\CacheProvisioningStep;
use App\Services\Provisioning\Steps\DatabaseProvisioningStep;
use App\Services\Provisioning\Steps\DependenciesProvisioningStep;
use App\Services\Provisioning\Steps\DeployProvisioningStep;
use App\Services\Provisioning\Steps\DnsProvisioningStep;
use App\Services\Provisioning\Steps\EnvironmentProvisioningStep;
use App\Services\Provisioning\Steps\HealthProvisioningStep;
use App\Services\Provisioning\Steps\HostingProvisioningStep;
use App\Services\Provisioning\Steps\MarkDeployedProvisioningStep;
use App\Services\Provisioning\Steps\MarkReadyProvisioningStep;
use App\Services\Provisioning\Steps\MarkVerifiedProvisioningStep;
use App\Services\Provisioning\Steps\MigrateProvisioningStep;
use App\Services\Provisioning\Steps\ModulesProvisioningStep;
use App\Services\Provisioning\Steps\ReserveProvisioningStep;
use App\Services\Provisioning\Steps\StorageProvisioningStep;
use App\Services\Provisioning\Steps\ValidateProvisioningStep;
use Illuminate\Contracts\Foundation\Application;

/**
 * Catalogue des 18 steps production (TASK 353 / DI adaptateurs TASK 354).
 */
final class ProductionProvisioningStepCatalog
{
    /**
     * @return list<ProvisioningStep>
     */
    public static function productionSteps(?Application $app = null): array
    {
        $app ??= app();

        return [
            $app->make(ValidateProvisioningStep::class),
            $app->make(ReserveProvisioningStep::class),
            $app->make(DnsProvisioningStep::class),
            $app->make(HostingProvisioningStep::class),
            $app->make(DatabaseProvisioningStep::class),
            $app->make(DeployProvisioningStep::class),
            $app->make(EnvironmentProvisioningStep::class),
            $app->make(DependenciesProvisioningStep::class),
            $app->make(BuildProvisioningStep::class),
            $app->make(MigrateProvisioningStep::class),
            $app->make(StorageProvisioningStep::class),
            $app->make(CacheProvisioningStep::class),
            $app->make(AdminProvisioningStep::class),
            $app->make(ModulesProvisioningStep::class),
            $app->make(HealthProvisioningStep::class),
            $app->make(MarkDeployedProvisioningStep::class),
            $app->make(MarkVerifiedProvisioningStep::class),
            $app->make(MarkReadyProvisioningStep::class),
        ];
    }

    public static function registerProductionSteps(ProvisioningStepRegistry $registry): void
    {
        foreach (self::productionSteps() as $step) {
            $registry->register($step);
        }
    }
}
