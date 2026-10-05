<?php

namespace Tests\Concerns;

use App\Services\Provisioning\Infrastructure\Local\LocalProvisioningInfrastructureBundle;
use App\Services\Provisioning\ProvisioningPersistedRunOrchestrator;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;

trait BindsLocalProvisioningInfrastructure
{
    protected function bindLocalProvisioningInfrastructure(): LocalProvisioningInfrastructureBundle
    {
        $bundle = LocalProvisioningInfrastructureBundle::createFresh();
        $bundle->registerInApplication($this->app);
        $this->app->forgetInstance(ProvisioningPersistedRunOrchestrator::class);
        $this->app->forgetInstance(ProvisioningPipeline::class);

        return $bundle;
    }

    protected function localProductionStepRegistry(): ProvisioningStepRegistry
    {
        $this->bindLocalProvisioningInfrastructure();

        return app(ProvisioningStepRegistry::class);
    }
}
