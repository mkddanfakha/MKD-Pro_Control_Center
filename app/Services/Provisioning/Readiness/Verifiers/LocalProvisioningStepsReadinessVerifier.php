<?php

namespace App\Services\Provisioning\Readiness\Verifiers;

use App\Contracts\Provisioning\Readiness\InstallationReadinessProofVerifier;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\Readiness\InstallationReadinessProofBuilder;
use App\Support\Provisioning\InstallationReadinessProofCatalog;

final class LocalProvisioningStepsReadinessVerifier implements InstallationReadinessProofVerifier
{
    private const SOURCE = 'local_provisioning_steps_verifier';

    public function verify(InstallationReadinessVerificationContext $context): array
    {
        if ($context->provisioningRunId === null) {
            return [];
        }

        $canonical = ProvisioningRunStep::CANONICAL_STEP_KEYS;
        $steps = $context->runSteps;

        $keysPresent = array_map(static fn ($step) => $step->stepKey, $steps);
        $uniqueKeys = array_values(array_unique($keysPresent));
        $missing = array_values(array_diff($canonical, $uniqueKeys));
        $allPresent = $missing === [] && count($uniqueKeys) === count($canonical);

        $duplicate = count($keysPresent) !== count($uniqueKeys);

        $orderCorrect = true;
        if ($allPresent && ! $duplicate) {
            $ordered = $steps;
            usort($ordered, static fn ($a, $b) => $a->stepOrder <=> $b->stepOrder);
            foreach ($ordered as $index => $step) {
                if ($step->stepKey !== $canonical[$index]) {
                    $orderCorrect = false;
                    break;
                }
            }
        } else {
            $orderCorrect = false;
        }

        $hasFailed = false;
        $allSucceeded = $allPresent && ! $duplicate;
        foreach ($steps as $step) {
            if ($step->status === ProvisioningRunStep::STATUS_FAILED) {
                $hasFailed = true;
            }
            if ($step->status !== ProvisioningRunStep::STATUS_SUCCEEDED) {
                $allSucceeded = false;
            }
        }

        return [
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEPS_PRESENT,
                $allPresent
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_VERIFIED,
                self::SOURCE,
                $allPresent ? 'provisioning_canonical_steps_present' : 'provisioning_canonical_steps_incomplete',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEP_ORDER,
                $orderCorrect
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_VERIFIED,
                self::SOURCE,
                $orderCorrect ? 'provisioning_canonical_step_order' : 'provisioning_canonical_step_order_invalid',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEPS_UNIQUE,
                ! $duplicate
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::FAILED,
                self::SOURCE,
                $duplicate ? 'provisioning_canonical_steps_duplicate' : 'provisioning_canonical_steps_unique',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_STEPS_NO_FAILED,
                ! $hasFailed
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::FAILED,
                self::SOURCE,
                $hasFailed ? 'provisioning_steps_failed_present' : 'provisioning_steps_no_failed',
                $context,
            ),
            InstallationReadinessProofBuilder::fromCatalog(
                InstallationReadinessProofCatalog::CODE_PROVISIONING_STEPS_ALL_SUCCEEDED,
                $allSucceeded
                    ? InstallationReadinessProofStatus::VERIFIED
                    : InstallationReadinessProofStatus::NOT_VERIFIED,
                self::SOURCE,
                $allSucceeded ? 'provisioning_steps_all_succeeded' : 'provisioning_steps_not_all_succeeded',
                $context,
            ),
        ];
    }
}
