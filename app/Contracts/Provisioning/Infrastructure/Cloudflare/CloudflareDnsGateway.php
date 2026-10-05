<?php

namespace App\Contracts\Provisioning\Infrastructure\Cloudflare;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsConfiguration;

/**
 * Port Cloudflare DNS — API v4 documentée (Bearer token, zones, dns_records).
 *
 * @see https://developers.cloudflare.com/api/
 */
interface CloudflareDnsGateway
{
    public function configureDns(
        ProvisioningContext $context,
        CloudflareDnsConfiguration $configuration,
    ): InfrastructureAdapterResult;
}
