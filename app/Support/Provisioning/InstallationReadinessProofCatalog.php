<?php

namespace App\Support\Provisioning;

use App\DTO\Provisioning\InstallationReadinessProofLevel;

/**
 * Catalogue central des codes de preuves readiness (TASK 380 / 381).
 *
 * @phpstan-type ProofDefinition array{
 *     domain: string,
 *     level: string,
 *     automatically_verifiable: bool,
 *     blocks_ready_when_unmet: bool,
 * }
 */
final class InstallationReadinessProofCatalog
{
    public const DOMAIN_INSTALLATION = 'installation';

    public const DOMAIN_PROVISIONING_RUN = 'provisioning_run';

    public const DOMAIN_PROVISIONING_STEPS = 'provisioning_steps';

    public const DOMAIN_CONTROL_CENTER_CONFIG = 'control_center_config';

    public const DOMAIN_DNS = 'dns';

    public const DOMAIN_HTTPS = 'https';

    public const DOMAIN_HOSTING = 'hosting';

    public const DOMAIN_INFRASTRUCTURE = 'infrastructure';

    public const CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED = 'infrastructure_cloudflare_dns_verified';

    public const CODE_INFRASTRUCTURE_O2SWITCH_DATABASE_VERIFIED = 'infrastructure_o2switch_database_verified';

    public const CODE_INFRASTRUCTURE_O2SWITCH_GIT_VERIFIED = 'infrastructure_o2switch_git_verified';

    public const CODE_INFRASTRUCTURE_O2SWITCH_FILEMAN_VERIFIED = 'infrastructure_o2switch_fileman_verified';

    public const CODE_INSTALLATION_RECORD_PRESENT = 'installation_record_present';

    public const CODE_INSTALLATION_NOT_TERMINATED = 'installation_not_terminated';

    public const CODE_SUBDOMAIN_CONFIGURATION_PRESENT = 'subdomain_configuration_present';

    public const CODE_DOMAIN_CONFIGURATION_PRESENT = 'domain_configuration_present';

    public const CODE_INSTALLATION_VERSION_RECORDED = 'installation_version_recorded';

    public const CODE_INSTALLATION_CLIENT_PRESENT = 'installation_client_present';

    public const CODE_INSTALLATION_DATABASE_CONFIGURATION_PRESENT = 'installation_database_configuration_present';

    public const CODE_INSTALLATION_STATUS_ELIGIBLE = 'installation_status_eligible';

    public const CODE_PROVISIONING_CAPACITY_RESERVATION_AVAILABLE = 'provisioning_capacity_reservation_available';

    public const DOMAIN_PROVISIONING_EXECUTION = 'provisioning_execution';

    public const CODE_PROVISIONING_RUN_PRESENT = 'provisioning_run_present';

    public const CODE_PROVISIONING_RUN_SUCCEEDED = 'provisioning_run_succeeded';

    public const CODE_PROVISIONING_RUN_NO_TERMINAL_FAILURE = 'provisioning_run_no_terminal_failure';

    public const CODE_PROVISIONING_CANONICAL_STEPS_PRESENT = 'provisioning_canonical_steps_present';

    public const CODE_PROVISIONING_CANONICAL_STEP_ORDER = 'provisioning_canonical_step_order';

    public const CODE_PROVISIONING_CANONICAL_STEPS_UNIQUE = 'provisioning_canonical_steps_unique';

    public const CODE_PROVISIONING_STEPS_NO_FAILED = 'provisioning_steps_no_failed';

    public const CODE_PROVISIONING_STEPS_ALL_SUCCEEDED = 'provisioning_steps_all_succeeded';

    public const CODE_CONTROL_CENTER_ENVIRONMENT_COHERENT = 'control_center_environment_coherent';

    public const CODE_PROVISIONING_CONFIGURATION_LOADED = 'provisioning_configuration_loaded';

    public const CODE_O2SWITCH_CPANEL_HOST_CONFIGURATION_PRESENT = 'o2switch_cpanel_host_configuration_present';

    public const CODE_CLOUDFLARE_ZONE_CONFIGURATION_PRESENT = 'cloudflare_zone_configuration_present';

    public const CODE_DNS_VERIFIED = 'dns_verified';

    public const CODE_HTTPS_VERIFIED = 'https_verified';

    public const CODE_HOSTING_VERIFIED = 'hosting_verified';

    public const CODE_DATABASE_SECURITY_ACCOUNTS_VERIFIED = 'database_security_accounts_verified';

    public const CODE_REMOTE_STORAGE_VERIFIED = 'remote_storage_verified';

    public const CODE_BACKUP_R2_VERIFIED = 'backup_r2_verified';

    public const CODE_BACKUP_MONITORING_VERIFIED = 'backup_monitoring_verified';

    /**
     * @return array<string, ProofDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::CODE_INSTALLATION_RECORD_PRESENT => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),
            self::CODE_INSTALLATION_NOT_TERMINATED => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),
            self::CODE_SUBDOMAIN_CONFIGURATION_PRESENT => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),
            self::CODE_DOMAIN_CONFIGURATION_PRESENT => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),
            self::CODE_INSTALLATION_VERSION_RECORDED => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_INSTALLATION_CLIENT_PRESENT => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),
            self::CODE_INSTALLATION_DATABASE_CONFIGURATION_PRESENT => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),
            self::CODE_INSTALLATION_STATUS_ELIGIBLE => self::def(self::DOMAIN_INSTALLATION, InstallationReadinessProofLevel::REQUIRED, true, true),

            self::CODE_PROVISIONING_CAPACITY_RESERVATION_AVAILABLE => self::def(self::DOMAIN_PROVISIONING_EXECUTION, InstallationReadinessProofLevel::REQUIRED, true, true),

            self::CODE_PROVISIONING_RUN_PRESENT => self::def(self::DOMAIN_PROVISIONING_RUN, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_RUN_SUCCEEDED => self::def(self::DOMAIN_PROVISIONING_RUN, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_RUN_NO_TERMINAL_FAILURE => self::def(self::DOMAIN_PROVISIONING_RUN, InstallationReadinessProofLevel::RECOMMENDED, true, false),

            self::CODE_PROVISIONING_CANONICAL_STEPS_PRESENT => self::def(self::DOMAIN_PROVISIONING_STEPS, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_CANONICAL_STEP_ORDER => self::def(self::DOMAIN_PROVISIONING_STEPS, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_CANONICAL_STEPS_UNIQUE => self::def(self::DOMAIN_PROVISIONING_STEPS, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_STEPS_NO_FAILED => self::def(self::DOMAIN_PROVISIONING_STEPS, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_STEPS_ALL_SUCCEEDED => self::def(self::DOMAIN_PROVISIONING_STEPS, InstallationReadinessProofLevel::RECOMMENDED, true, false),

            self::CODE_CONTROL_CENTER_ENVIRONMENT_COHERENT => self::def(self::DOMAIN_CONTROL_CENTER_CONFIG, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_PROVISIONING_CONFIGURATION_LOADED => self::def(self::DOMAIN_CONTROL_CENTER_CONFIG, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_O2SWITCH_CPANEL_HOST_CONFIGURATION_PRESENT => self::def(self::DOMAIN_CONTROL_CENTER_CONFIG, InstallationReadinessProofLevel::OPTIONAL, true, false),
            self::CODE_CLOUDFLARE_ZONE_CONFIGURATION_PRESENT => self::def(self::DOMAIN_CONTROL_CENTER_CONFIG, InstallationReadinessProofLevel::OPTIONAL, true, false),

            self::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED => self::def(self::DOMAIN_INFRASTRUCTURE, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_INFRASTRUCTURE_O2SWITCH_DATABASE_VERIFIED => self::def(self::DOMAIN_INFRASTRUCTURE, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_INFRASTRUCTURE_O2SWITCH_GIT_VERIFIED => self::def(self::DOMAIN_INFRASTRUCTURE, InstallationReadinessProofLevel::RECOMMENDED, true, false),
            self::CODE_INFRASTRUCTURE_O2SWITCH_FILEMAN_VERIFIED => self::def(self::DOMAIN_INFRASTRUCTURE, InstallationReadinessProofLevel::RECOMMENDED, true, false),

            self::CODE_DNS_VERIFIED => self::def(self::DOMAIN_DNS, InstallationReadinessProofLevel::REQUIRED, false, true),
            self::CODE_HTTPS_VERIFIED => self::def(self::DOMAIN_HTTPS, InstallationReadinessProofLevel::REQUIRED, false, true),
            self::CODE_HOSTING_VERIFIED => self::def(self::DOMAIN_HOSTING, InstallationReadinessProofLevel::REQUIRED, false, true),

            InstallationReadinessContract::CODE_APPLICATION_ACCESSIBLE => self::def(InstallationReadinessContract::DOMAIN_APPLICATION, InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_DATABASE_CONNECTION => self::def(InstallationReadinessContract::DOMAIN_DATABASE, InstallationReadinessProofLevel::REQUIRED, false, true),
            self::CODE_DATABASE_SECURITY_ACCOUNTS_VERIFIED => self::def(InstallationReadinessContract::DOMAIN_DATABASE, InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_BUILD_MANIFEST => self::def(InstallationReadinessContract::DOMAIN_BUILD, InstallationReadinessProofLevel::REQUIRED, false, true),
            self::CODE_REMOTE_STORAGE_VERIFIED => self::def('storage', InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_HEALTH_CATALOG => self::def(InstallationReadinessContract::DOMAIN_HEALTH, InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_ADMIN_BOOTSTRAP => self::def(InstallationReadinessContract::DOMAIN_ADMIN, InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_BACKUP_VERIFIED => self::def(InstallationReadinessContract::DOMAIN_BACKUP, InstallationReadinessProofLevel::REQUIRED, false, true),
            self::CODE_BACKUP_R2_VERIFIED => self::def(InstallationReadinessContract::DOMAIN_BACKUP, InstallationReadinessProofLevel::REQUIRED, false, true),
            self::CODE_BACKUP_MONITORING_VERIFIED => self::def(InstallationReadinessContract::DOMAIN_BACKUP, InstallationReadinessProofLevel::RECOMMENDED, false, false),
            InstallationReadinessContract::CODE_WORKER_VERIFIED => self::def(InstallationReadinessContract::DOMAIN_WORKER, InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_SCHEDULER_VERIFIED => self::def(InstallationReadinessContract::DOMAIN_SCHEDULER, InstallationReadinessProofLevel::REQUIRED, false, true),
            InstallationReadinessContract::CODE_WEBROOT_SENSITIVE_ABSENT => self::def(InstallationReadinessContract::DOMAIN_WEBROOT, InstallationReadinessProofLevel::REQUIRED, false, true),
        ];
    }

    /**
     * @return ProofDefinition|null
     */
    public static function definition(string $code): ?array
    {
        return self::definitions()[$code] ?? null;
    }

    /**
     * Codes REQUIRED pour une décision READY client (inclut preuves non automatisées).
     *
     * @return list<string>
     */
    public static function requiredProofCodesForClientReady(): array
    {
        $codes = [];
        foreach (self::definitions() as $code => $definition) {
            if ($definition['level'] === InstallationReadinessProofLevel::REQUIRED
                && $definition['blocks_ready_when_unmet']) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Preuves obligatoires avant toute exécution pipeline (TASK 3W) — distinct du READY client Gestion.
     *
     * @return list<string>
     */
    public static function requiredProofCodesForProvisioningExecution(): array
    {
        return [
            self::CODE_INSTALLATION_RECORD_PRESENT,
            self::CODE_INSTALLATION_NOT_TERMINATED,
            self::CODE_INSTALLATION_CLIENT_PRESENT,
            self::CODE_INSTALLATION_STATUS_ELIGIBLE,
            self::CODE_SUBDOMAIN_CONFIGURATION_PRESENT,
            self::CODE_DOMAIN_CONFIGURATION_PRESENT,
            self::CODE_INSTALLATION_DATABASE_CONFIGURATION_PRESENT,
            self::CODE_PROVISIONING_CONFIGURATION_LOADED,
            self::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED,
            self::CODE_INFRASTRUCTURE_O2SWITCH_DATABASE_VERIFIED,
            self::CODE_INFRASTRUCTURE_O2SWITCH_GIT_VERIFIED,
            self::CODE_INFRASTRUCTURE_O2SWITCH_FILEMAN_VERIFIED,
            self::CODE_PROVISIONING_CAPACITY_RESERVATION_AVAILABLE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function locallyAutomatableProofCodes(): array
    {
        $codes = [];
        foreach (self::definitions() as $code => $definition) {
            if ($definition['automatically_verifiable']) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public static function preflightMandatoryServiceKeys(): array
    {
        return [
            'cloudflare_dns',
            'o2switch_database',
            'o2switch_git',
            'o2switch_fileman',
        ];
    }

    public static function proofCodeForPreflightServiceKey(string $serviceKey): string
    {
        return match ($serviceKey) {
            'cloudflare_dns' => self::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED,
            'o2switch_database' => self::CODE_INFRASTRUCTURE_O2SWITCH_DATABASE_VERIFIED,
            'o2switch_git' => self::CODE_INFRASTRUCTURE_O2SWITCH_GIT_VERIFIED,
            'o2switch_fileman' => self::CODE_INFRASTRUCTURE_O2SWITCH_FILEMAN_VERIFIED,
            default => throw new \InvalidArgumentException('Service préflight inconnu : '.$serviceKey),
        };
    }

    /**
     * @return list<string>
     */
    public static function externalNotYetAutomatedProofCodes(): array
    {
        $codes = [];
        foreach (self::definitions() as $code => $definition) {
            if (! $definition['automatically_verifiable']
                && $definition['level'] === InstallationReadinessProofLevel::REQUIRED) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @return array{domain: string, level: string, automatically_verifiable: bool, blocks_ready_when_unmet: bool}
     */
    private static function def(string $domain, string $level, bool $automatable, bool $blocksReady): array
    {
        return [
            'domain' => $domain,
            'level' => $level,
            'automatically_verifiable' => $automatable,
            'blocks_ready_when_unmet' => $blocksReady,
        ];
    }
}
