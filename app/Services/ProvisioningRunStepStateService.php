<?php

namespace App\Services;

use App\Exceptions\InvalidProvisioningRunStepTransition;
use App\Models\ProvisioningRunStep;
use Illuminate\Support\Carbon;

/**
 * Transitions locales des ProvisioningRunStep — séparé du run car états et règles distincts (ex. skipped).
 *
 * Le retry provisioning reste un nouveau ProvisioningRun (retry_of_run_id) ; attempt n'est pas auto-incrémenté ici.
 */
class ProvisioningRunStepStateService
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        ProvisioningRunStep::STATUS_PENDING => [
            ProvisioningRunStep::STATUS_RUNNING,
            ProvisioningRunStep::STATUS_SKIPPED,
        ],
        ProvisioningRunStep::STATUS_RUNNING => [
            ProvisioningRunStep::STATUS_SUCCEEDED,
            ProvisioningRunStep::STATUS_FAILED,
            ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ],
    ];

    /**
     * @var list<string>
     */
    private const TERMINAL_STATUSES = [
        ProvisioningRunStep::STATUS_SUCCEEDED,
        ProvisioningRunStep::STATUS_FAILED,
        ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ProvisioningRunStep::STATUS_SKIPPED,
    ];

    public function canTransitionTo(ProvisioningRunStep $step, string $toStatus): bool
    {
        try {
            $this->assertTransitionAllowed($step, $toStatus);

            return true;
        } catch (InvalidProvisioningRunStepTransition) {
            return false;
        }
    }

    public function transitionTo(ProvisioningRunStep $step, string $toStatus, ?Carbon $at = null): ProvisioningRunStep
    {
        $fromStatus = $step->status;

        $this->assertTransitionAllowed($step, $toStatus);

        $at ??= now();

        if ($fromStatus === ProvisioningRunStep::STATUS_PENDING && $toStatus === ProvisioningRunStep::STATUS_RUNNING) {
            if ($step->started_at === null) {
                $step->started_at = $at;
            }
        }

        if (in_array($toStatus, self::TERMINAL_STATUSES, true) && $step->finished_at === null) {
            $step->finished_at = $at;
        }

        $step->status = $toStatus;
        $step->save();

        return $step->refresh();
    }

    private function assertTransitionAllowed(ProvisioningRunStep $step, string $toStatus): void
    {
        $fromStatus = $step->status;

        if ($step->finished_at !== null) {
            throw new InvalidProvisioningRunStepTransition(
                $fromStatus,
                $toStatus,
                $step->id,
                'Transition d\'étape interdite : l\'étape est terminalisée (finished_at renseigné).',
            );
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new InvalidProvisioningRunStepTransition($fromStatus, $toStatus, $step->id);
        }
    }
}
