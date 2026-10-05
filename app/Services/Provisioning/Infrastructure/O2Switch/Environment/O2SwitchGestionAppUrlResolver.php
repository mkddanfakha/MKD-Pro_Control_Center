<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * APP_URL Gestion — domaine explicite ou subdomain + base domain configurée (TASK 361).
 */
final class O2SwitchGestionAppUrlResolver
{
    public static function resolve(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
    ): ?string {
        if ($context->domain !== null && trim($context->domain) !== '') {
            return self::toHttpsUrl(trim($context->domain));
        }

        if ($configuration->gestionAppBaseDomain === '') {
            return null;
        }

        $subdomain = strtolower(trim($context->subdomain));
        if ($subdomain === '' || ! self::isValidHostnameLabel($subdomain)) {
            return null;
        }

        $base = strtolower(trim($configuration->gestionAppBaseDomain));
        if (! self::isValidHostname($base)) {
            return null;
        }

        return self::toHttpsUrl($subdomain.'.'.$base);
    }

    private static function toHttpsUrl(string $host): ?string
    {
        $host = strtolower(trim($host));

        if (! self::isValidHostname($host)) {
            return null;
        }

        return 'https://'.$host;
    }

    private static function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1;
    }

    private static function isValidHostnameLabel(string $label): bool
    {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1;
    }
}
