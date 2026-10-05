<?php

namespace App\Services\Provisioning\Infrastructure\Cloudflare;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * Dérivation du nom d'enregistrement DNS à partir du contexte (sans secret).
 */
final class CloudflareDnsRecordNaming
{
    public static function recordFqdn(ProvisioningContext $context, CloudflareDnsConfiguration $configuration): ?string
    {
        if ($context->domain !== null && trim($context->domain) !== '') {
            return strtolower(trim($context->domain));
        }

        if ($configuration->zoneName === '') {
            return null;
        }

        $subdomain = strtolower(trim($context->subdomain));

        if ($subdomain === '') {
            return null;
        }

        return $subdomain.'.'.strtolower($configuration->zoneName);
    }
}
