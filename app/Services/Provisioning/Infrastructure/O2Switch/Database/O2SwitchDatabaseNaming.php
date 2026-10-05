<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Database;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * Nom de base MySQL déterministe — contrainte identifiant MySQL (64 octets max, documentée MySQL 8).
 *
 * Sur mutualisé o2switch/cPanel, le préfixe compte (`database_name_prefix`) est imposé par le panel.
 */
final class O2SwitchDatabaseNaming
{
    public const MYSQL_IDENTIFIER_MAX_BYTES = 64;

    public static function resolve(
        ProvisioningContext $context,
        O2SwitchDatabaseConfiguration $configuration,
    ): ?O2SwitchDatabaseNamingResolution {
        $prefix = strtolower($configuration->databaseNamePrefix);

        if ($context->databaseName !== null && trim($context->databaseName) !== '') {
            $full = self::sanitizeIdentifier(trim($context->databaseName));
            if ($full === null || ! self::fitsMaxLength($full)) {
                return null;
            }

            if ($prefix !== '' && ! str_starts_with($full, $prefix)) {
                return null;
            }

            $suffix = $prefix !== '' ? substr($full, strlen($prefix)) : $full;

            return new O2SwitchDatabaseNamingResolution(
                fullDatabaseName: $full,
                cpanelCreateSuffix: $suffix,
            );
        }

        if ($prefix === '') {
            return null;
        }

        $subdomain = self::sanitizeIdentifier($context->subdomain);
        if ($subdomain === null || $subdomain === '') {
            return null;
        }

        $suffixBody = self::sanitizeIdentifier('gest_'.$subdomain.'_'.$context->installationId);
        if ($suffixBody === null || $suffixBody === '') {
            return null;
        }

        $maxSuffixBytes = self::MYSQL_IDENTIFIER_MAX_BYTES - strlen($prefix);
        if ($maxSuffixBytes < 1) {
            return null;
        }

        $suffix = self::truncateToMaxBytes($suffixBody, $maxSuffixBytes);
        $full = $prefix.$suffix;

        if (! self::fitsMaxLength($full)) {
            return null;
        }

        return new O2SwitchDatabaseNamingResolution(
            fullDatabaseName: $full,
            cpanelCreateSuffix: $suffix,
        );
    }

    public static function sanitizeIdentifier(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^[0-9]/', $normalized) === 1) {
            $normalized = 'db_'.$normalized;
        }

        return $normalized;
    }

    public static function fitsMaxLength(string $identifier): bool
    {
        return strlen($identifier) <= self::MYSQL_IDENTIFIER_MAX_BYTES;
    }

    private static function truncateToMaxBytes(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes);
    }
}
