<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * Chemin distant de déploiement — base configurée + segment contrôlé (TASK 360).
 *
 * Ne dérive pas le chemin uniquement depuis le subdomain ; utilise l'identifiant d'installation
 * ou un segment explicite dans `externalReferences.deploy_relative_path`.
 */
final class O2SwitchDeployPathResolver
{
    public static function resolve(
        ProvisioningContext $context,
        O2SwitchDeployConfiguration $configuration,
    ): ?O2SwitchDeployPathResolution {
        $base = $configuration->deploymentRootBase;
        if (! self::isValidAbsoluteBase($base)) {
            return null;
        }

        $relative = self::relativeSegment($context);
        if ($relative === null) {
            return null;
        }

        $absolute = rtrim($base, '/').'/'.$relative;

        if (! self::isValidAbsolutePath($absolute)) {
            return null;
        }

        return new O2SwitchDeployPathResolution($absolute, $relative);
    }

    private static function relativeSegment(ProvisioningContext $context): ?string
    {
        $explicit = $context->externalReferences['deploy_relative_path'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return self::sanitizeRelativePath(trim($explicit));
        }

        return 'mkd_gestion/installation_'.$context->installationId;
    }

    private static function sanitizeRelativePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = trim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }

            if (preg_match('/^[a-zA-Z0-9._-]+$/', $segment) !== 1) {
                return null;
            }
        }

        return $normalized;
    }

    private static function isValidAbsoluteBase(string $base): bool
    {
        return self::isValidAbsolutePath($base);
    }

    private static function isValidAbsolutePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (! str_starts_with($path, '/')) {
            return false;
        }

        if (str_contains($path, '..')) {
            return false;
        }

        return preg_match('#^/[a-zA-Z0-9/._-]+$#', $path) === 1;
    }
}
