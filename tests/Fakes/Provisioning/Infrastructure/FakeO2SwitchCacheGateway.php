<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchCacheGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheCommandPlan;
use App\Services\Provisioning\Infrastructure\O2Switch\Cache\O2SwitchCacheConfiguration;

final class FakeO2SwitchCacheGateway implements O2SwitchCacheGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function warmCache(
        ProvisioningContext $context,
        O2SwitchCacheConfiguration $configuration,
        O2SwitchCacheCommandPlan $commandPlan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'operation' => $commandPlan->safeOperationLabel(),
            'cache_fingerprint' => $commandPlan->cacheFingerprint,
            'planned_commands' => $commandPlan->plannedCommandNames(),
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_cache',
            'Fake gateway par défaut.',
        );
    }
}
