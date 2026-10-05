<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

use App\Contracts\Provisioning\Infrastructure\ApplicationDeployAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDeployGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchDeployAdapter implements ApplicationDeployAdapter
{
    public function __construct(
        private readonly O2SwitchDeployGateway $gateway,
        private readonly ?O2SwitchDeployConfiguration $configuration = null,
    ) {}

    public function deployApplication(ProvisioningContext $context): InfrastructureAdapterResult
    {
        $configuration = $this->configuration ?? O2SwitchDeployConfiguration::fromApplicationConfig();

        if (! $configuration->enabled) {
            return $this->manualIntervention(
                'o2switch_deploy_disabled',
                'Déploiement o2switch désactivé (PROVISIONING_O2SWITCH_DEPLOY_ENABLED=false) : aucune opération externe.',
                $configuration,
                $context,
            );
        }

        if ($configuration->dryRun) {
            if (! $configuration->isDryRunReady()) {
                return $this->manualIntervention(
                    'o2switch_deploy_not_configured',
                    'Déploiement o2switch en dry-run mais configuration incomplète (compte, racine distante, dépôt Gestion).',
                    $configuration,
                    $context,
                );
            }

            $path = O2SwitchDeployPathResolver::resolve($context, $configuration);
            if ($path === null) {
                return $this->manualIntervention(
                    'o2switch_deploy_invalid_path',
                    'Chemin de déploiement distant invalide ou racine non configurée.',
                    $configuration,
                    $context,
                );
            }

            $revision = O2SwitchDeployRevision::resolve($context, $configuration);
            if ($revision === null) {
                return $this->manualIntervention(
                    'o2switch_deploy_not_configured',
                    'Référence Git absente : renseigner target_commit, target_version ou default_git_ref.',
                    $configuration,
                    $context,
                );
            }

            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'simulated_domain' => 'application_deploy',
                    'deploy_path' => $path->absoluteDeployPath,
                    'git_reference' => $revision->reference,
                    'git_reference_kind' => $revision->referenceKind,
                    'repository_url' => $configuration->gestionGitRepositoryUrl,
                    'dry_run' => true,
                ],
                metadata: [
                    'execution_mode' => 'dry_run',
                    'simulated' => true,
                    'operation' => 'application_deploy',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        if (! $configuration->isLiveReady()) {
            return $this->manualIntervention(
                'o2switch_deploy_not_configured',
                'Déploiement o2switch activé mais configuration incomplète (cPanel, secrets, chemins, dépôt).',
                $configuration,
                $context,
            );
        }

        return $this->gateway->deployApplication($context, $configuration);
    }

    private function manualIntervention(
        string $code,
        string $message,
        O2SwitchDeployConfiguration $configuration,
        ProvisioningContext $context,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            $code,
            sprintf('%s (installation #%d).', $message, $context->installationId),
            outputSummary: [
                'implementation_state' => 'not_operational',
                'contract' => 'application_deploy',
            ],
            metadata: [
                'implementation_state' => 'not_operational',
                'operation' => 'application_deploy',
                'installation_id' => $context->installationId,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
