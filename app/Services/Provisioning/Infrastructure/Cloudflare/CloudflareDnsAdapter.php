<?php

namespace App\Services\Provisioning\Infrastructure\Cloudflare;

use App\Contracts\Provisioning\Infrastructure\Cloudflare\CloudflareDnsGateway;
use App\Contracts\Provisioning\Infrastructure\DnsRecordAdapter;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Adaptateur DNS production — Cloudflare (TASK 358).
 */
final class CloudflareDnsAdapter implements DnsRecordAdapter
{
    public function __construct(
        private readonly CloudflareDnsGateway $gateway,
        private readonly ?CloudflareDnsConfiguration $configuration = null,
    ) {}

    public function configureDns(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? CloudflareDnsConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'cloudflare_dns_disabled',
                'DNS Cloudflare désactivé (PROVISIONING_CLOUDFLARE_DNS_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if ($configuration->dryRun) {
            if (! $configuration->isDryRunReady()) {
                return $this->manualIntervention(
                    'cloudflare_dns_not_configured',
                    'DNS Cloudflare en dry-run mais zone (nom ou identifiant) manquante.',
                    $configuration,
                    $context,
                );
            }

            $recordName = CloudflareDnsRecordNaming::recordFqdn($context, $configuration);
            if ($recordName === null) {
                return $this->manualIntervention(
                    'cloudflare_dns_context_incomplete',
                    'Impossible de déterminer le nom DNS : subdomain ou domain requis.',
                    $configuration,
                    $context,
                );
            }

            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'dns',
                    'record_name' => $recordName,
                    'record_type' => $configuration->recordType,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'dns',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'cloudflare_dns_not_configured',
                'DNS Cloudflare activé mais configuration incomplète (zone et token API requis).',
                $configuration,
                $context,
            );
        }

        return $this->gateway->configureDns($context, $configuration);
    }

    private function manualIntervention(
        string $code,
        string $message,
        CloudflareDnsConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'dns',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'dns',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
