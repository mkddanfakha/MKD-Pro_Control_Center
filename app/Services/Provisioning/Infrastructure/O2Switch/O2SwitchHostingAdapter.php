<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch;

use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHostingGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Adaptateur Hosting production — o2switch (TASK 357).
 *
 * Par défaut : aucun réseau, aucune mutation o2switch.
 */
final class O2SwitchHostingAdapter implements HostingSpaceAdapter
{
    public function __construct(
        private readonly O2SwitchHostingGateway $gateway,
        private readonly ?O2SwitchHostingConfiguration $configuration = null,
    ) {}

    public function prepareHosting(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchHostingConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_hosting_disabled',
                'Hébergement o2switch désactivé (PROVISIONING_O2SWITCH_HOSTING_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if (! $configuration->isOperationallyConfigured()) {
            return $this->manualIntervention(
                'o2switch_hosting_not_configured',
                'Hébergement o2switch activé mais configuration incomplète (identifiant logique de compte manquant).',
                $configuration,
                $context,
            );
        }

        if ($configuration->dryRun) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'hosting',
                    'document_root' => '/dry-run/simulated/'.$context->installationId.'/public',
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'hosting_panel',
                    'provider' => $configuration->provider,
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        return $this->gateway->prepareHostingSpace($context, $configuration);
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchHostingConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'hosting_panel',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'hosting_panel',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
