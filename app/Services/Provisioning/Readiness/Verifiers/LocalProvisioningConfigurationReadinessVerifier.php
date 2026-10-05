<?php

namespace App\Services\Provisioning\Readiness\Verifiers;

use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Services\Provisioning\Readiness\InstallationReadinessProofBuilder;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

final class LocalProvisioningConfigurationReadinessVerifier implements InstallationReadinessProofVerifier
{
    private const SOURCE = 'local_provisioning_configuration_verifier';

    public function verify(InstallationReadinessVerificationContext $context): array
    {
        $flags = $context->provisioningConfigFlags;

        $configLoaded = ($flags['provisioning_config_exists'] ?? false) === true;

        $envCoherent = in_array($flags['app_env'] ?? null, ['local', 'testing', 'staging', 'production'], true);

        $cpanelHostConfigured = ($flags['o2switch_cpanel_host_configured'] ?? false) === true;

        $cloudflareZoneConfigured = ($flags['cloudflare_zone_name_configured'] ?? false) === true;

        return [
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_CONFIGURATION_LOADED,
                $configLoaded
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_CONFIGURED,
                self::SOURCE,
                $configLoaded ? 'provisioning_configuration_loaded' : 'provisioning_configuration_missing',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_CONTROL_CENTER_ENVIRONMENT_COHERENT,
                $envCoherent
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_VERIFIED,
                self::SOURCE,
                $envCoherent ? 'control_center_environment_coherent' : 'control_center_environment_unexpected',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_O2SWITCH_CPANEL_HOST_CONFIGURATION_PRESENT,
                $cpanelHostConfigured
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_CONFIGURED,
                self::SOURCE,
                $cpanelHostConfigured
                    ? 'o2switch_cpanel_host_configuration_present'
                    : 'o2switch_cpanel_host_configuration_missing',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_CLOUDFLARE_ZONE_CONFIGURATION_PRESENT,
                $cloudflareZoneConfigured
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_CONFIGURED,
                self::SOURCE,
                $cloudflareZoneConfigured
                    ? 'cloudflare_zone_configuration_present'
                    : 'cloudflare_zone_configuration_missing',
                $context,
            ),
        ];
    }
}
