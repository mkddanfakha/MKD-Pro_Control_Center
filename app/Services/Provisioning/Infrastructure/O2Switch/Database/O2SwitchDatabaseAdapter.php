<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Database;

use App\Contracts\Provisioning\Infrastructure\ClientDatabaseAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDatabaseGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Adaptateur Database production — MySQL o2switch (TASK 359).
 */
final class O2SwitchDatabaseAdapter implements ClientDatabaseAdapter
{
    public function __construct(
        private readonly O2SwitchDatabaseGateway $gateway,
        private readonly ?O2SwitchDatabaseConfiguration $configuration = null,
    ) {}

    public function provisionDatabase(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchDatabaseConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_database_disabled',
                'Base MySQL o2switch désactivée (PROVISIONING_O2SWITCH_DATABASE_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if ($configuration->dryRun) {
            if (! $configuration->isDryRunReady()) {
                return $this->manualIntervention(
                    'o2switch_database_not_configured',
                    'Base o2switch en dry-run mais configuration incomplète (identifiant logique et préfixe de nom requis).',
                    $configuration,
                    $context,
                );
            }

            $naming = O2SwitchDatabaseNaming::resolve($context, $configuration);
            if ($naming === null) {
                return $this->manualIntervention(
                    'o2switch_database_context_incomplete',
                    'Impossible de déterminer un nom de base valide (subdomain, préfixe ou database_name).',
                    $configuration,
                    $context,
                );
            }

            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'client_database',
                    'database_name' => $naming->fullDatabaseName,
                    'database_host' => $configuration->mysqlHostLogical,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'client_database',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_database_not_configured',
                'Base o2switch activée mais configuration incomplète (compte, préfixe, hôte cPanel, token API).',
                $configuration,
                $context,
            );
        }

        return $this->gateway->provisionDatabase($context, $configuration);
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchDatabaseConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'client_database',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'client_database',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
