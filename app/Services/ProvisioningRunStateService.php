<?php

namespace App\Services;

use App\Exceptions\InvalidProvisioningRunTransition;
use App\Models\ProvisioningRun;
use Illuminate\Support\Carbon;

/**
 * Validation et transitions locales des ProvisioningRun (sans moteur de provisioning).
 *
 * Concurrence : garantie SQL via active_installation_key ; création de run = transaction future.
 * Audit / readiness : branchés ultérieurement (provisioning.*, installation.deployed|verified|ready).
 */
class ProvisioningRunStateService
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        ProvisioningRun::STATUS_PENDING => [
            ProvisioningRun::STATUS_RUNNING,
            ProvisioningRun::STATUS_CANCELLED,
        ],
        ProvisioningRun::STATUS_RUNNING => [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ],
    ];

    /**
     * @var list<string>
     */
    private const TERMINAL_STATUSES = [
        ProvisioningRun::STATUS_SUCCEEDED,
        ProvisioningRun::STATUS_FAILED,
        ProvisioningRun::STATUS_CANCELLED,
        ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
    ];

    public function canTransitionTo(ProvisioningRun $run, string $toStatus): bool
    {
        try {
            $this->assertTransitionAllowed($run, $toStatus);

            return true;
        } catch (InvalidProvisioningRunTransition) {
            return false;
        }
    }

    public function transitionTo(ProvisioningRun $run, string $toStatus, ?Carbon $at = null): ProvisioningRun
    {
        $fromStatus = $run->status;

        $this->assertTransitionAllowed($run, $toStatus);

        $at ??= now();

        if ($fromStatus === ProvisioningRun::STATUS_PENDING && $toStatus === ProvisioningRun::STATUS_RUNNING) {
            if ($run->started_at === null) {
                $run->started_at = $at;
            }
        }

        if (in_array($toStatus, self::TERMINAL_STATUSES, true) && $run->finished_at === null) {
            $run->finished_at = $at;
        }

        $run->status = $toStatus;
        $run->save();

        return $run->refresh();
    }

    public function setCurrentStep(ProvisioningRun $run, string $stepKey): ProvisioningRun
    {
        if ($run->finished_at !== null) {
            throw new InvalidProvisioningRunTransition(
                $run->status,
                $run->status,
                $run->id,
                'Impossible de définir current_step : le run est terminal (finished_at renseigné).',
            );
        }

        if ($run->status !== ProvisioningRun::STATUS_RUNNING) {
            throw new InvalidProvisioningRunTransition(
                $run->status,
                ProvisioningRun::STATUS_RUNNING,
                $run->id,
                sprintf(
                    'Impossible de définir current_step : le run doit être en état %s.',
                    ProvisioningRun::STATUS_RUNNING,
                ),
            );
        }

        $run->current_step = $stepKey;
        $run->save();

        return $run->refresh();
    }

    private function assertTransitionAllowed(ProvisioningRun $run, string $toStatus): void
    {
        $fromStatus = $run->status;

        if ($run->finished_at !== null) {
            throw new InvalidProvisioningRunTransition(
                $fromStatus,
                $toStatus,
                $run->id,
                sprintf(
                    'Transition de provisioning interdite : le run est terminal (finished_at renseigné).',
                ),
            );
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new InvalidProvisioningRunTransition($fromStatus, $toStatus, $run->id);
        }
    }
}
