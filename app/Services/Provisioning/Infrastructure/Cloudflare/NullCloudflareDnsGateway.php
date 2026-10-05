<?php

namespace App\Services\Provisioning\Infrastructure\Cloudflare;

use App\Contracts\Provisioning\Infrastructure\Cloudflare\CloudflareDnsGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Gateway placeholder — branchement HTTP Cloudflare non activé en production (TASK 358).
 */
final class NullCloudflareDnsGateway implements CloudflareDnsGateway
{
    public function configureDns(
        ProvisioningContext $context,
        CloudflareDnsConfiguration $configuration,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'cloudflare_dns_protocol_pending',
            sprintf(
                'Le gateway Cloudflare HTTP n\'est pas branché pour modifier le DNS (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'dns',
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'dns',
                'provider' => $configuration->provider,
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
