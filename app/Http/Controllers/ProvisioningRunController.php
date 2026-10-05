<?php

namespace App\Http\Controllers;

use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\Exceptions\InvalidProvisioningRunTransition;
use App\Exceptions\Provisioning\ProvisioningExecutableRegistryEmptyException;
use App\Exceptions\Provisioning\ProvisioningStepExecutionException;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\ProvisioningExecutionAuditService;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use App\Services\Provisioning\Readiness\InstallationReadinessEvaluationService;
use App\Support\Provisioning\ProvisioningInstallationReadinessPresentation;
use App\Support\ProvisioningRunAdminPresentation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProvisioningRunController extends Controller
{
    /**
     * Consultation d'un run et de ses étapes.
     */
    public function show(ProvisioningRun $provisioningRun): Response
    {
        $provisioningRun->load([
            'installation.client:id,company_name,contact_name,status',
            'steps' => fn ($query) => $query->orderBy('step_order')->orderBy('id'),
        ]);

        $steps = $provisioningRun->steps;

        $installation = $provisioningRun->installation;
        $client = $installation?->client;

        $registry = app(ProvisioningStepRegistry::class);
        $readiness = app(InstallationReadinessEvaluationService::class)->evaluateProvisioningRun(
            $provisioningRun,
            preflightReport: null,
            runPreflightWhenMissing: false,
        );

        return Inertia::render('ProvisioningRuns/Show', [
            'provisioning_run' => ProvisioningRunAdminPresentation::run($provisioningRun),
            'step_statistics' => ProvisioningRunAdminPresentation::stepStatistics($steps),
            'steps' => $steps->map(
                fn ($step) => ProvisioningRunAdminPresentation::step($step),
            )->values()->all(),
            'provisioning_readiness' => ProvisioningInstallationReadinessPresentation::present($readiness),
            'execute_actions' => ProvisioningRunAdminPresentation::describeExecuteAvailability(
                $provisioningRun,
                $registry,
                $readiness,
            ),
            'navigation' => [
                'installation_show' => $installation !== null
                    ? route('installations.show', $installation)
                    : null,
                'client_show' => $client !== null
                    ? route('clients.show', $client)
                    : null,
                'retry_parent_show' => $provisioningRun->retry_of_run_id !== null
                    ? route('provisioning-runs.show', $provisioningRun->retry_of_run_id)
                    : null,
            ],
        ]);
    }

    /**
     * Lance l'orchestration persistée d'un run pending (TASK 356).
     */
    public function execute(
        ProvisioningRun $provisioningRun,
        ProvisioningPipeline $pipeline,
        ProvisioningStepRegistry $registry,
    ): RedirectResponse {
        $provisioningRun->loadMissing(['installation.client', 'steps']);

        $readiness = app(InstallationReadinessEvaluationService::class)->evaluateProvisioningRun(
            $provisioningRun,
            preflightReport: null,
            runPreflightWhenMissing: false,
        );

        $executeAvailability = ProvisioningRunAdminPresentation::describeExecuteAvailability(
            $provisioningRun,
            $registry,
            $readiness,
        );

        if (! $executeAvailability['can_execute']) {
            $reason = $executeAvailability['unavailable_reason']
                ?? 'Exécution impossible pour ce run.';
            app(ProvisioningExecutionAuditService::class)->recordExecutionRejected($provisioningRun, $reason);

            return redirect()
                ->route('provisioning-runs.show', $provisioningRun)
                ->with('error', $reason);
        }

        try {
            $result = $pipeline->runPersisted($provisioningRun);
        } catch (ProvisioningExecutableRegistryEmptyException) {
            return redirect()
                ->route('provisioning-runs.show', $provisioningRun)
                ->with(
                    'error',
                    'Aucune étape exécutable n\'est enregistrée ; le provisioning n\'a pas démarré.',
                );
        } catch (InvalidProvisioningRunTransition $exception) {
            app(ProvisioningExecutionAuditService::class)->recordExecutionRejected(
                $provisioningRun,
                $exception->getMessage(),
            );

            return redirect()
                ->route('provisioning-runs.show', $provisioningRun)
                ->with(
                    'error',
                    'Exécution refusée : '.$exception->getMessage(),
                );
        } catch (ProvisioningStepExecutionException $exception) {
            $message = $exception->stepResult->operatorMessage
                ?? 'Une erreur inattendue est survenue pendant le provisioning.';

            return redirect()
                ->route('provisioning-runs.show', $provisioningRun)
                ->with(
                    'error',
                    sprintf(
                        'Provisioning en échec à l\'étape « %s » : %s',
                        $exception->stepKey,
                        $message,
                    ),
                );
        }

        return redirect()
            ->route('provisioning-runs.show', $provisioningRun)
            ->with(
                $this->flashKeyForPipelineOutcome($result->outcome),
                $this->flashMessageForPipelineResult($result),
            );
    }

    private function flashKeyForPipelineOutcome(string $outcome): string
    {
        return $outcome === ProvisioningPipelineResult::OUTCOME_SUCCEEDED ? 'success' : 'error';
    }

    private function flashMessageForPipelineResult(ProvisioningPipelineResult $result): string
    {
        return match ($result->outcome) {
            ProvisioningPipelineResult::OUTCOME_SUCCEEDED => 'Provisioning terminé avec succès.',
            ProvisioningPipelineResult::OUTCOME_FAILED => sprintf(
                'Provisioning en échec%s.',
                $result->stoppedAtStepKey !== null
                    ? ' à l\'étape « '.$result->stoppedAtStepKey.' »'
                    : '',
            ),
            ProvisioningPipelineResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED => sprintf(
                'Provisioning suspendu : intervention manuelle requise%s.',
                $result->stoppedAtStepKey !== null
                    ? ' à l\'étape « '.$result->stoppedAtStepKey.' »'
                    : '',
            ),
            default => 'Provisioning terminé avec un état inattendu.',
        };
    }
}
