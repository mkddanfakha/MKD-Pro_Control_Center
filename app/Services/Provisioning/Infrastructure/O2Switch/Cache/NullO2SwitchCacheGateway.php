<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Cache;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchCacheGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class NullO2SwitchCacheGateway implements O2SwitchCacheGateway
{
    public function warmCache(
        ProvisioningContext $context,
        O2SwitchCacheConfiguration $configuration,
        O2SwitchCacheCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'o2switch_cache_protocol_pending',
            sprintf(
                'Aucun gateway Cache o2switch opérationnel n\'est enregistré (installation #%d).',
                $context->installationId,
            ),
            outputSummary: [
                'implementation_state' => 'protocol_pending',
                'contract' => 'cache_warmup',
                'operation_planned' => $commandPlan->safeOperationLabel(),
            ],
            metadata: [
                'implementation_state' => 'protocol_pending',
                'operation' => 'cache_warmup',
                'installation_id' => $context->installationId,
                'cache_fingerprint' => $commandPlan->cacheFingerprint,
                'planned_cache_commands' => $commandPlan->plannedCommandNames(),
                ...$commandPlan->deploymentTarget->safePublicMetadata(),
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
