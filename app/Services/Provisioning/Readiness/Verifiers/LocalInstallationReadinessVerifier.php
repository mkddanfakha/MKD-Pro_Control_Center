<?php

namespace App\Services\Provisioning\Readiness\Verifiers;

use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Services\Provisioning\Readiness\InstallationReadinessProofBuilder;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

final class LocalInstallationReadinessVerifier implements InstallationReadinessProofVerifier
{
    private const SOURCE = 'local_installation_verifier';

    public function verify(InstallationReadinessVerificationContext $context): array
    {
        $proofs = [];

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_INSTALLATION_RECORD_PRESENT,
            $context->installationId !== null
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_VERIFIED,
            self::SOURCE,
            $context->installationId !== null ? 'installation_record_present' : 'installation_record_missing',
            $context,
        );

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_INSTALLATION_NOT_TERMINATED,
            ! $context->installationTerminated
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::FAILED,
            self::SOURCE,
            $context->installationTerminated ? 'installation_terminated' : 'installation_not_terminated',
            $context,
        );

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_SUBDOMAIN_CONFIGURATION_PRESENT,
            $context->subdomainPresent()
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_CONFIGURED,
            self::SOURCE,
            $context->subdomainPresent() ? 'subdomain_configuration_present' : 'subdomain_configuration_missing',
            $context,
        );

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_DOMAIN_CONFIGURATION_PRESENT,
            $context->domainPresent()
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_CONFIGURED,
            self::SOURCE,
            $context->domainPresent() ? 'domain_configuration_present' : 'domain_configuration_missing',
            $context,
        );

        $versionRecorded = trim((string) $context->installationVersion) !== ''
            || trim((string) $context->targetVersion) !== ''
            || trim((string) $context->targetCommit) !== '';

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_INSTALLATION_VERSION_RECORDED,
            $versionRecorded
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_VERIFIED,
            self::SOURCE,
            $versionRecorded ? 'installation_version_recorded' : 'installation_version_not_recorded',
            $context,
        );

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_INSTALLATION_CLIENT_PRESENT,
            $context->clientRecordPresent
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_VERIFIED,
            self::SOURCE,
            $context->clientRecordPresent ? 'installation_client_present' : 'installation_client_missing',
            $context,
        );

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_INSTALLATION_DATABASE_CONFIGURATION_PRESENT,
            $context->databaseConfigurationPresent()
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_CONFIGURED,
            self::SOURCE,
            $context->databaseConfigurationPresent()
                ? 'installation_database_configuration_present'
                : 'installation_database_configuration_incomplete',
            $context,
        );

        $proofs[] = InstallationReadinessProofBuilder::fromCatalog(
            InstallationReadinessProofCatalog::CODE_INSTALLATION_STATUS_ELIGIBLE,
            $context->installationStatusEligibleForProvisioning()
                ? InstallationReadinessProofStatus::VERIFIED
                : InstallationReadinessProofStatus::NOT_VERIFIED,
            self::SOURCE,
            $context->installationStatusEligibleForProvisioning()
                ? 'installation_status_eligible'
                : 'installation_status_not_eligible',
            $context,
        );

        return $proofs;
    }
}
