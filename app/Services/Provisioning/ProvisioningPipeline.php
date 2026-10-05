<?php

namespace App\Services\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningPipelinePlan;
use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Models\ProvisioningRun;
use InvalidArgumentException;

/**
 * Orchestrateur futur — TASK 339 : planification et cohérence uniquement, aucun effet externe.
 *
 * Couches séparées : orchestration (ici) / steps+adaptateurs / StateService / persistance / audit / readiness.
 * Pas de transaction DB globale sur le pipeline (effets externes non annulables par MySQL).
 */
class ProvisioningPipeline
{
    public function __construct(
        private readonly ProvisioningStepRegistry $registry,
        private readonly ?ProvisioningPersistedRunOrchestrator $persistedRunOrchestrator = null,
    ) {}

    /**
     * Orchestration persistée d'un {@see ProvisioningRun} (MySQL, transitions StateServices).
     */
    public function runPersisted(ProvisioningRun $run): ProvisioningPipelineResult
    {
        return $this->persistedRunOrchestrator()->runPersisted($run);
    }

    private function persistedRunOrchestrator(): ProvisioningPersistedRunOrchestrator
    {
        return $this->persistedRunOrchestrator ?? app(ProvisioningPersistedRunOrchestrator::class);
    }

    public function buildPlan(ProvisioningRun $run): ProvisioningPipelinePlan
    {
        $this->assertRunEligibleForPlanning($run);

        $keys = $this->registry->orderedStepKeys();
        $this->registry->assertRegistryIntegrity($keys);

        return new ProvisioningPipelinePlan(
            provisioningRunId: (int) $run->id,
            orderedStepKeys: $keys,
        );
    }

    /**
     * Vérifie que les étapes suivantes ne sont pas planifiables après un échec simulé sur une étape obligatoire.
     *
     * @return list<string> step_keys qui doivent rester bloqués
     */
    public function stepsBlockedAfterFailure(string $failedStepKey): array
    {
        if (! $this->registry->contains($failedStepKey)) {
            throw new InvalidArgumentException("Étape inconnue : {$failedStepKey}.");
        }

        $failedIndex = $this->registry->orderIndexOf($failedStepKey);
        $all = $this->registry->orderedStepKeys();

        return array_values(array_slice($all, $failedIndex + 1));
    }

    public function assertRetryUsesNewRun(ProvisioningRun $priorRun, ProvisioningRun $retryRun): void
    {
        if ($priorRun->finished_at === null) {
            throw new InvalidArgumentException('Le run source doit être terminal avant un retry.');
        }

        if ($retryRun->retry_of_run_id !== $priorRun->id) {
            throw new InvalidArgumentException('Le retry doit pointer retry_of_run_id vers le run source.');
        }

        if ($priorRun->status === ProvisioningRun::STATUS_RUNNING) {
            throw new InvalidArgumentException('Un run source running ne peut pas être réouvert.');
        }
    }

    /**
     * Orchestration pure : exécute les étapes enregistrées, sans persistance run/step ni effet externe.
     */
    public function run(ProvisioningContext $context): ProvisioningPipelineResult
    {
        $steps = $this->registry->orderedExecutableSteps();
        $results = [];

        foreach ($steps as $step) {
            $result = $step->execute($context);
            $results[] = $result;

            if ($result->outcome === ProvisioningStepResult::OUTCOME_FAILED) {
                return ProvisioningPipelineResult::failed($result, array_slice($results, 0, -1));
            }

            if ($result->outcome === ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED) {
                return ProvisioningPipelineResult::manualInterventionRequired($result, array_slice($results, 0, -1));
            }
        }

        return ProvisioningPipelineResult::succeeded($results);
    }

    /**
     * @deprecated Utiliser {@see run()} avec un {@see ProvisioningContext}. Conservé pour compatibilité tests planification.
     */
    public function execute(ProvisioningRun $run): never
    {
        throw new \BadMethodCallException(
            'Utiliser ProvisioningPipeline::run(ProvisioningContext) — aucune exécution automatique depuis ProvisioningRun.',
        );
    }

    private function assertRunEligibleForPlanning(ProvisioningRun $run): void
    {
        if ($run->id === null) {
            throw new InvalidArgumentException('ProvisioningRun doit être persisté pour planifier le pipeline.');
        }

        $terminalStatuses = [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ];

        if (in_array($run->status, $terminalStatuses, true) && $run->finished_at !== null) {
            throw new InvalidArgumentException(
                'Un run terminal ne peut pas être replanifié sur le même enregistrement — utiliser retry_of_run_id.',
            );
        }
    }
}
