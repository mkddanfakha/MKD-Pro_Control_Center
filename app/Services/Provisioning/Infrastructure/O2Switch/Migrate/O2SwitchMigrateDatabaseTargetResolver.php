<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRun;

final class O2SwitchMigrateDatabaseTargetResolver
{
    /**
     * @param  list<string>  $forbiddenDatabaseNames
     * @return array{target: ?O2SwitchMigrateDatabaseTarget, code: ?string, message: ?string}
     */
    public static function resolve(
        ProvisioningContext $context,
        array $forbiddenDatabaseNames,
    ): array {
        if ($context->installation->id !== $context->installationId) {
            return self::invalid('Identifiant installation incohérent dans le contexte.');
        }

        $terminalRunStatuses = [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_CANCELLED,
        ];

        if (in_array($context->provisioningRun->status, $terminalRunStatuses, true)) {
            return self::invalid('Run de provisioning déjà clos — migration non éligible.');
        }

        $overrideInstallationId = $context->externalReferences['migration_target_installation_id']
            ?? $context->externalReferences['target_installation_id']
            ?? null;

        if ($overrideInstallationId !== null && (int) $overrideInstallationId !== $context->installationId) {
            return self::invalid('Référence externe tente de cibler une autre installation.');
        }

        $databaseName = $context->databaseName;
        $databaseHost = $context->databaseHost;

        if ($databaseName === null || trim($databaseName) === '') {
            return self::invalid('database_name absent sur l\'installation.');
        }

        if ($databaseHost === null || trim($databaseHost) === '') {
            return self::invalid('database_host absent sur l\'installation.');
        }

        $databaseName = trim($databaseName);
        $databaseHost = trim($databaseHost);

        foreach (['database_name', 'database_host', 'migration_database_name', 'migration_database_host'] as $overrideKey) {
            if (! array_key_exists($overrideKey, $context->externalReferences)) {
                continue;
            }

            $overrideValue = $context->externalReferences[$overrideKey];
            if (! is_string($overrideValue) || trim($overrideValue) === '') {
                continue;
            }

            $normalizedOverride = self::normalizeDatabaseIdentifier($overrideValue);
            $expected = str_contains($overrideKey, 'host')
                ? self::normalizeDatabaseHost($databaseHost)
                : self::normalizeDatabaseIdentifier($databaseName);

            if ($normalizedOverride !== $expected) {
                return self::invalid('Référence externe incohérente avec la base de l\'installation.');
            }
        }

        $normalizedName = self::normalizeDatabaseIdentifier($databaseName);

        foreach ($forbiddenDatabaseNames as $forbidden) {
            if (! is_string($forbidden) || $forbidden === '') {
                continue;
            }

            if ($normalizedName === self::normalizeDatabaseIdentifier($forbidden)) {
                return self::invalid('Base interdite (Control Center ou liste de sécurité).');
            }
        }

        $fingerprint = hash('sha256', json_encode([
            'installation_id' => $context->installationId,
            'database_name' => $normalizedName,
            'database_host' => self::normalizeDatabaseHost($databaseHost),
        ], JSON_THROW_ON_ERROR));

        return [
            'target' => new O2SwitchMigrateDatabaseTarget(
                installationId: $context->installationId,
                databaseName: $databaseName,
                databaseHost: $databaseHost,
                targetFingerprint: $fingerprint,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * @return array{target: null, code: string, message: string}
     */
    private static function invalid(string $message): array
    {
        return [
            'target' => null,
            'code' => 'o2switch_migrate_database_target_invalid',
            'message' => $message,
        ];
    }

    private static function normalizeDatabaseIdentifier(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function normalizeDatabaseHost(string $value): string
    {
        return strtolower(trim($value));
    }
}
