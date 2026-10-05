<?php

namespace App\Support\Provisioning;

/**
 * Codes et domaines de preuves readiness — contrat interne (TASK 380).
 *
 * Alimentation future : vérificateurs health / verification / ops.
 */
final class InstallationReadinessContract
{
    public const DOMAIN_APPLICATION = 'application';

    public const DOMAIN_DATABASE = 'database';

    public const DOMAIN_BUILD = 'build';

    public const DOMAIN_HEALTH = 'health';

    public const DOMAIN_ADMIN = 'admin_bootstrap';

    public const DOMAIN_BACKUP = 'backup';

    public const DOMAIN_WORKER = 'worker';

    public const DOMAIN_SCHEDULER = 'scheduler';

    public const DOMAIN_WEBROOT = 'webroot_security';

    public const CODE_APPLICATION_ACCESSIBLE = 'application_accessible';

    public const CODE_DATABASE_CONNECTION = 'database_connection_verified';

    public const CODE_BUILD_MANIFEST = 'build_manifest_verified';

    public const CODE_HEALTH_CATALOG = 'health_catalog_verified';

    public const CODE_ADMIN_BOOTSTRAP = 'admin_bootstrap_verified';

    public const CODE_BACKUP_VERIFIED = 'backup_verified';

    public const CODE_WORKER_VERIFIED = 'worker_verified';

    public const CODE_SCHEDULER_VERIFIED = 'scheduler_verified';

    public const CODE_WEBROOT_SENSITIVE_ABSENT = 'webroot_sensitive_files_absent';

    /**
     * Jeu REQUIRED représentatif pour scénarios type installation Gestion industrialisée (fictif).
     *
     * @return list<string>
     */
    public static function representativeRequiredProofCodes(): array
    {
        return [
            self::CODE_APPLICATION_ACCESSIBLE,
            self::CODE_DATABASE_CONNECTION,
            self::CODE_BUILD_MANIFEST,
            self::CODE_HEALTH_CATALOG,
            self::CODE_ADMIN_BOOTSTRAP,
            self::CODE_BACKUP_VERIFIED,
            self::CODE_WORKER_VERIFIED,
            self::CODE_SCHEDULER_VERIFIED,
            self::CODE_WEBROOT_SENSITIVE_ABSENT,
        ];
    }

    /**
     * @see InstallationReadinessProofCatalog::requiredProofCodesForClientReady()
     *
     * @return list<string>
     */
    public static function fullRequiredProofCodesForClientReady(): array
    {
        return InstallationReadinessProofCatalog::requiredProofCodesForClientReady();
    }
}
