<?php

namespace App\Services\Provisioning;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\AuditLogService;
use App\Support\ProvisioningAuditPayloadSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traçabilité des exécutions provisioning via {@see AuditLogService} — TASK 371.
 *
 * Ordre : transition / persistance d'abord, puis appel à ce service.
 * Échec d'écriture audit : journalisé, n'altère pas l'état du run.
 */
final class ProvisioningExecutionAuditService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordRequestCreated(ProvisioningRun $run): void
    {
        $run->loadMissing('installation');

        $this->safeRecord(
            'provisioning.request_created',
            $run,
            null,
            $this->runContext($run) + [
                'status' => $run->status,
                'trigger' => $run->trigger,
            ],
        );
    }

    public function recordRetryCreated(ProvisioningRun $run): void
    {
        if ($run->retry_of_run_id === null) {
            return;
        }

        $this->safeRecord(
            'provisioning.retry_created',
            $run,
            null,
            $this->runContext($run) + [
                'status' => $run->status,
                'prior_provisioning_run_id' => $run->retry_of_run_id,
            ],
        );
    }

    public function recordRunStarted(ProvisioningRun $run, string $statusBefore): void
    {
        $run->refresh();

        $this->safeRecord(
            'provisioning.started',
            $run,
            ['status' => $statusBefore],
            $this->runContext($run) + ['status' => $run->status],
        );
    }

    public function recordRunCompleted(ProvisioningRun $run, string $statusBefore): void
    {
        $run->refresh();

        $this->safeRecord(
            'provisioning.completed',
            $run,
            ['status' => $statusBefore],
            $this->runContext($run) + ['status' => $run->status],
        );
    }

    public function recordRunFailed(ProvisioningRun $run, string $statusBefore, ?string $errorCode, ?string $summary): void
    {
        $run->refresh();

        $this->safeRecord(
            'provisioning.failed',
            $run,
            ['status' => $statusBefore],
            $this->runContext($run) + array_filter([
                'status' => $run->status,
                'error_code' => $errorCode ?? $run->error_code,
                'summary' => $summary ?? $run->error_message,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    public function recordRunManualInterventionRequired(
        ProvisioningRun $run,
        string $statusBefore,
        ?string $errorCode,
        ?string $summary,
    ): void {
        $run->refresh();

        $this->safeRecord(
            'provisioning.manual_intervention_required',
            $run,
            ['status' => $statusBefore],
            $this->runContext($run) + array_filter([
                'status' => $run->status,
                'error_code' => $errorCode ?? $run->error_code,
                'summary' => $summary ?? $run->error_message,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    public function recordStepStarted(ProvisioningRun $run, ProvisioningRunStep $step, string $statusBefore): void
    {
        $step->refresh();

        $this->safeRecord(
            'provisioning.step_started',
            $step,
            ['status' => $statusBefore],
            $this->stepContext($run, $step) + ['status' => $step->status],
        );
    }

    /**
     * @param  array<string, mixed>|null  $outputSummary
     */
    public function recordStepSucceeded(ProvisioningRun $run, ProvisioningRunStep $step, string $statusBefore, ?array $outputSummary): void
    {
        $step->refresh();

        $this->safeRecord(
            'provisioning.step_succeeded',
            $step,
            ['status' => $statusBefore],
            $this->stepContext($run, $step) + array_filter([
                'status' => $step->status,
                'output_summary' => $outputSummary,
            ], fn ($v) => $v !== null && $v !== []),
        );
    }

    public function recordStepSkipped(ProvisioningRun $run, ProvisioningRunStep $step, string $statusBefore): void
    {
        $step->refresh();

        $this->safeRecord(
            'provisioning.step_skipped',
            $step,
            ['status' => $statusBefore],
            $this->stepContext($run, $step) + ['status' => $step->status],
        );
    }

    public function recordStepFailed(
        ProvisioningRun $run,
        ProvisioningRunStep $step,
        string $statusBefore,
        ?string $errorCode,
        ?string $summary,
    ): void {
        $step->refresh();

        $this->safeRecord(
            'provisioning.step_failed',
            $step,
            ['status' => $statusBefore],
            $this->stepContext($run, $step) + array_filter([
                'status' => $step->status,
                'error_code' => $errorCode ?? $step->error_code,
                'summary' => $summary ?? $step->error_message,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    public function recordStepManualInterventionRequired(
        ProvisioningRun $run,
        ProvisioningRunStep $step,
        string $statusBefore,
        ?string $errorCode,
        ?string $summary,
    ): void {
        $step->refresh();

        $this->safeRecord(
            'provisioning.step_manual_intervention_required',
            $step,
            ['status' => $statusBefore],
            $this->stepContext($run, $step) + array_filter([
                'status' => $step->status,
                'error_code' => $errorCode ?? $step->error_code,
                'summary' => $summary ?? $step->error_message,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    public function recordExecutionRejected(ProvisioningRun $run, string $reason): void
    {
        $this->safeRecord(
            'provisioning.execution_rejected',
            $run,
            ['status' => $run->status],
            $this->runContext($run) + [
                'status' => $run->status,
                'reason' => $reason,
            ],
            result: 'failure',
            errorMessage: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runContext(ProvisioningRun $run): array
    {
        $installation = $run->installation;

        return array_filter([
            'provisioning_run_id' => $run->id,
            'installation_id' => $run->installation_id,
            'client_id' => $installation?->client_id,
            'trigger' => $run->trigger,
            'retry_of_run_id' => $run->retry_of_run_id,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepContext(ProvisioningRun $run, ProvisioningRunStep $step): array
    {
        return $this->runContext($run) + array_filter([
            'provisioning_run_step_id' => $step->id,
            'step_key' => $step->step_key,
            'step_order' => $step->step_order,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function safeRecord(
        string $action,
        Model $auditable,
        ?array $oldValues,
        ?array $newValues,
        string $result = 'success',
        ?string $errorMessage = null,
    ): void {
        try {
            $this->auditLogService->record(
                $action,
                $auditable,
                ProvisioningAuditPayloadSanitizer::sanitize($oldValues),
                ProvisioningAuditPayloadSanitizer::sanitize($newValues),
                $result,
                $errorMessage,
            );
        } catch (Throwable $exception) {
            Log::warning('provisioning_audit_write_failed', [
                'action' => $action,
                'auditable_type' => $auditable->getMorphClass(),
                'auditable_id' => $auditable->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
