<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

interface HostingSpaceAdapter
{
    public function prepareHosting(ProvisioningContext $context): InfrastructureAdapterResult;
}
