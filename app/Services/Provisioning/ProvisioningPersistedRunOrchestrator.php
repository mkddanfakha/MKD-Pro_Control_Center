<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\ProvisioningStep;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\DTO\Provisioning\ProvisioningPipelineResult;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Exceptions\InvalidProvisioningRunTransition;
use App\Exceptions\Provisioning\ProvisioningExecutableRegistryEmptyException;
use App\Exceptions\Provisioning\ProvisioningStepExecutionException;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\ProvisioningRunStateService;
use App\Services\ProvisioningRunStepStateService;
use App\Support\ProvisioningExecutionDiagnostics;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Orchestration persistée d'un ProvisioningRun — transitions via StateServices uniquement.
 *
 * Aucun effet externe infrastructure. Les étapes exécutables viennent du registre.
 */
final class ProvisioningPersistedRunOrchestrator
{
    public function __construct(
        private readonly ProvisioningStepRegistry $registry,
        private readonly ProvisioningRunStateService $runStateService,
        private readonly ProvisioningRunStepStateService $stepStateService,
        private readonly ProvisioningExecutionAuditService $executionAuditService,
    ) {}

    /**
     * Exécute un run pending : pending → running → steps → terminal run.
     *
     * Reprise partielle : non supportée — seul un run {@see ProvisioningRun::STATUS_PENDING} est éligible.
     */
    public function runPersisted(ProvisioningRun $run): ProvisioningPipelineResult
    {
        $this->assertExecutableRegistryNotEmpty();

        $run = $this->claimPendingRunForExecution($run);
        $this->executionAuditService->recordRunStarted($run, ProvisioningRun::STATUS_PENDING);

        $run->loadMissing('installation.client');
        $context = ProvisioningContext::fromRun($run);

        $executableSteps = $this->registry->orderedExecutableSteps();
        $persistedByKey = $this->syncPersistedSteps($run, $executableSteps);

        $results = [];

        foreach ($executableSteps as $stepImpl) {
            $persisted = $persistedByKey[$stepImpl->stepKey()];

            if ($this->isPersistedStepTerminal($persisted)) {
                continue;
            }

            $run = $this->runStateService->setCurrentStep($run, $stepImpl->stepKey());

            try {
                $result = $stepImpl->execute($context);
            } catch (Throwable $exception) {
                $failedResult = $this->finalizeUncaughtStepFailure(
                    $run,
                    $persisted,
                    $stepImpl->stepKey(),
                    $exception,
                );

                throw new ProvisioningStepExecutionException(
                    $stepImpl->stepKey(),
                    $failedResult,
                    $exception,
                );
            }

            $this->applyNonTransitionStepFields($persisted, $result);

            if ($result->outcome === ProvisioningStepResult::OUTCOME_SKIPPED) {
                $statusBeforeSkip = $persisted->status;
                $this->stepStateService->transitionTo($persisted, ProvisioningRunStep::STATUS_SKIPPED);
                $this->executionAuditService->recordStepSkipped($run, $persisted->fresh(), $statusBeforeSkip);
                $results[] = $result;

                continue;
            }

            $statusBeforeRunning = $persisted->status;
            $this->stepStateService->transitionTo($persisted, ProvisioningRunStep::STATUS_RUNNING);
            $persisted = $persisted->fresh();
            $this->executionAuditService->recordStepStarted($run, $persisted, $statusBeforeRunning);

            $terminalStepStatus = match ($result->outcome) {
                ProvisioningStepResult::OUTCOME_SUCCEEDED => ProvisioningRunStep::STATUS_SUCCEEDED,
                ProvisioningStepResult::OUTCOME_FAILED => ProvisioningRunStep::STATUS_FAILED,
                ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED => ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
                default => throw new InvalidArgumentException('Outcome d\'étape inattendu : '.$result->outcome),
            };

            $statusBeforeTerminal = $persisted->status;
            $this->stepStateService->transitionTo($persisted, $terminalStepStatus);
            $persisted = $persisted->fresh();
            $this->auditStepTerminalOutcome($run, $persisted, $statusBeforeTerminal, $result);
            $results[] = $result;

            if ($result->outcome === ProvisioningStepResult::OUTCOME_FAILED) {
                $this->finalizeRunFromStepFailure($run, $result, ProvisioningRun::STATUS_FAILED);

                return ProvisioningPipelineResult::failed($result, array_slice($results, 0, -1));
            }

            if ($result->outcome === ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED) {
                $this->finalizeRunFromStepFailure($run, $result, ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED);

                return ProvisioningPipelineResult::manualInterventionRequired($result, array_slice($results, 0, -1));
            }
        }

        $statusBeforeSucceeded = $run->fresh()->status;
        $run = $this->runStateService->transitionTo($run->fresh(), ProvisioningRun::STATUS_SUCCEEDED);
        $this->executionAuditService->recordRunCompleted($run, $statusBeforeSucceeded);

        return ProvisioningPipelineResult::succeeded($results);
    }

    private function assertExecutableRegistryNotEmpty(): void
    {
        if ($this->registry->isEmpty()) {
            throw new ProvisioningExecutableRegistryEmptyException;
        }
    }

    private function claimPendingRunForExecution(ProvisioningRun $run): ProvisioningRun
    {
        if ($run->id === null) {
            throw new InvalidArgumentException('ProvisioningRun doit être persisté avant orchestration.');
        }

        $connection = $run->getConnectionName() ?? config('database.default');

        return DB::connection($connection)->transaction(function () use ($run): ProvisioningRun {
            /** @var ProvisioningRun|null $locked */
            $locked = ProvisioningRun::on($run->getConnectionName())
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new InvalidArgumentException('ProvisioningRun introuvable.');
            }

            $this->assertRunEligibleForPersistedExecution($locked);

            return $this->runStateService->transitionTo($locked, ProvisioningRun::STATUS_RUNNING);
        });
    }

    private function assertRunEligibleForPersistedExecution(ProvisioningRun $run): void
    {
        if ($run->id === null) {
            throw new InvalidArgumentException('ProvisioningRun doit être persisté avant orchestration.');
        }

        $terminalStatuses = [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ];

        if (in_array($run->status, $terminalStatuses, true)) {
            throw new InvalidProvisioningRunTransition(
                $run->status,
                ProvisioningRun::STATUS_RUNNING,
                $run->id,
                'Un run terminal ne peut pas être réexécuté sur le même enregistrement.',
            );
        }

        if ($run->status !== ProvisioningRun::STATUS_PENDING) {
            throw new InvalidProvisioningRunTransition(
                $run->status,
                ProvisioningRun::STATUS_RUNNING,
                $run->id,
                'Seul un run pending peut démarrer l\'orchestration persistée (reprise partielle non supportée).',
            );
        }

        if ($run->finished_at !== null) {
            throw new InvalidProvisioningRunTransition(
                $run->status,
                ProvisioningRun::STATUS_RUNNING,
                $run->id,
                'Impossible d\'orchestrer un run avec finished_at déjà renseigné.',
            );
        }
    }

    /**
     * @param  list<ProvisioningStep>  $executableSteps
     * @return array<string, ProvisioningRunStep>
     */
    private function syncPersistedSteps(ProvisioningRun $run, array $executableSteps): array
    {
        $map = [];

        foreach ($executableSteps as $stepImpl) {
            /** @var ProvisioningRunStep|null $existing */
            $existing = $run->steps()
                ->where('step_key', $stepImpl->stepKey())
                ->first();

            if ($existing === null) {
                $existing = $run->steps()->create([
                    'step_key' => $stepImpl->stepKey(),
                    'step_order' => $stepImpl->order(),
                    'status' => ProvisioningRunStep::STATUS_PENDING,
                    'attempt' => 1,
                ]);
            } elseif ((int) $existing->step_order !== $stepImpl->order()) {
                $existing->step_order = $stepImpl->order();
                $existing->save();
            }

            $map[$stepImpl->stepKey()] = $existing;
        }

        return $map;
    }

    private function isPersistedStepTerminal(ProvisioningRunStep $step): bool
    {
        return $step->finished_at !== null || in_array($step->status, [
            ProvisioningRunStep::STATUS_SUCCEEDED,
            ProvisioningRunStep::STATUS_FAILED,
            ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
            ProvisioningRunStep::STATUS_SKIPPED,
        ], true);
    }

    private function applyNonTransitionStepFields(ProvisioningRunStep $step, ProvisioningStepResult $result): void
    {
        $step->output_summary = $result->outputSummary;
        $step->metadata = $result->metadata;
        $step->error_code = $result->code;
        $step->error_message = $result->operatorMessage;
        $step->save();
    }

    private function finalizeRunFromStepFailure(
        ProvisioningRun $run,
        ProvisioningStepResult $result,
        string $runStatus,
    ): void {
        $run->error_code = $result->code;
        $run->error_message = $result->operatorMessage;
        $run->save();

        $statusBefore = $run->fresh()->status;
        $run = $this->runStateService->transitionTo($run->fresh(), $runStatus);

        if ($runStatus === ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED) {
            $this->executionAuditService->recordRunManualInterventionRequired(
                $run,
                $statusBefore,
                $result->code,
                $result->operatorMessage,
            );
        } else {
            $this->executionAuditService->recordRunFailed(
                $run,
                $statusBefore,
                $result->code,
                $result->operatorMessage,
            );
        }
    }

    private function auditStepTerminalOutcome(
        ProvisioningRun $run,
        ProvisioningRunStep $step,
        string $statusBefore,
        ProvisioningStepResult $result,
    ): void {
        match ($result->outcome) {
            ProvisioningStepResult::OUTCOME_SUCCEEDED => $this->executionAuditService->recordStepSucceeded(
                $run,
                $step,
                $statusBefore,
                $result->outputSummary,
            ),
            ProvisioningStepResult::OUTCOME_FAILED => $this->executionAuditService->recordStepFailed(
                $run,
                $step,
                $statusBefore,
                $result->code,
                $result->operatorMessage,
            ),
            ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED => $this->executionAuditService->recordStepManualInterventionRequired(
                $run,
                $step,
                $statusBefore,
                $result->code,
                $result->operatorMessage,
            ),
            default => null,
        };
    }

    private function finalizeUncaughtStepFailure(
        ProvisioningRun $run,
        ProvisioningRunStep $step,
        string $stepKey,
        Throwable $exception,
    ): ProvisioningStepResult {
        $code = ProvisioningExecutionDiagnostics::safeErrorCode($exception);
        $message = ProvisioningExecutionDiagnostics::safeOperatorMessage($exception);

        $failedResult = ProvisioningStepResult::failed(
            $stepKey,
            $code,
            $message,
            retryable: true,
            category: ProvisioningErrorCategory::Retryable,
            metadata: [
                'exception_class' => $exception::class,
            ],
        );

        $step->error_code = $code;
        $step->error_message = $message;
        $step->output_summary = $failedResult->outputSummary;
        $step->metadata = $failedResult->metadata;
        $step->save();

        $stepFresh = $step->fresh();

        if ($stepFresh->status === ProvisioningRunStep::STATUS_PENDING) {
            $statusBeforeRunning = $stepFresh->status;
            $this->stepStateService->transitionTo($stepFresh, ProvisioningRunStep::STATUS_RUNNING);
            $stepFresh = $stepFresh->fresh();
            $this->executionAuditService->recordStepStarted($run, $stepFresh, $statusBeforeRunning);
        }

        $statusBeforeFailed = $stepFresh->status;
        $this->stepStateService->transitionTo($stepFresh, ProvisioningRunStep::STATUS_FAILED);
        $stepFresh = $stepFresh->fresh();
        $this->executionAuditService->recordStepFailed($run, $stepFresh, $statusBeforeFailed, $code, $message);

        $run->error_code = $code;
        $run->error_message = sprintf('Étape « %s » : %s', $stepKey, $message);
        $run->save();

        $statusBeforeRunFailed = $run->fresh()->status;
        $run = $this->runStateService->transitionTo($run->fresh(), ProvisioningRun::STATUS_FAILED);
        $this->executionAuditService->recordRunFailed($run, $statusBeforeRunFailed, $code, $run->error_message);

        return $failedResult;
    }
}
