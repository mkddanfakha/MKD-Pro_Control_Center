<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Modules;

use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Gestion\GestionModulesCatalog;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchModulesPlanResolver
{
    /**
     * @param  list<string>  $moduleIds
     * @return array{
     *     plan: O2SwitchModulesPlan|null,
     *     code: string|null,
     *     message: string|null
     * }
     */
    public static function resolve(
        O2SwitchStorageDeploymentTarget $deploymentTarget,
        ProvisioningContext $context,
        array $moduleIds,
        bool $dryRunPlanning,
    ): array {
        if ($moduleIds === []) {
            return [
                'plan' => self::buildPlan(
                    $deploymentTarget,
                    [],
                    [],
                    [],
                    [],
                ),
                'code' => null,
                'message' => null,
            ];
        }

        $activatableIds = [];
        $noopIds = [];
        $logicalOperations = [];

        foreach ($moduleIds as $moduleId) {
            if (! GestionModulesCatalog::exists($moduleId)) {
                return [
                    'plan' => null,
                    'code' => 'o2switch_modules_unknown_module',
                    'message' => sprintf('Module inconnu dans le catalogue Gestion : %s.', $moduleId),
                ];
            }

            if (GestionModulesCatalog::isActivatableViaProvisioning($moduleId)) {
                $activatableIds[] = $moduleId;

                foreach (self::missingDependencies($context, $moduleId) as $missingRef) {
                    return [
                        'plan' => null,
                        'code' => 'o2switch_modules_dependency_missing',
                        'message' => sprintf(
                            'Dépendance non satisfaite pour %s : %s.',
                            $moduleId,
                            $missingRef,
                        ),
                    ];
                }

                $logicalOperations = array_merge(
                    $logicalOperations,
                    self::logicalOperationsForModule($moduleId),
                );

                continue;
            }

            if (GestionModulesCatalog::acceptsNoopRequest($moduleId)) {
                $noopIds[] = $moduleId;

                continue;
            }

            return [
                'plan' => null,
                'code' => 'o2switch_modules_not_activatable',
                'message' => sprintf(
                    'Le module %s n’est pas activable via provisioning distant.',
                    $moduleId,
                ),
            ];
        }

        $activatableIds = array_values(array_unique($activatableIds));
        sort($activatableIds);
        $noopIds = array_values(array_unique($noopIds));
        sort($noopIds);
        $logicalOperations = array_values(array_unique($logicalOperations));
        sort($logicalOperations);

        $artisanSteps = [];

        if ($activatableIds !== []) {
            if ($dryRunPlanning) {
                foreach (O2SwitchModulesArtisanCommandPolicy::statusArtisanStep() as $step) {
                    $artisanSteps[] = $step;
                }
                foreach (O2SwitchModulesArtisanCommandPolicy::catalogDryRunArtisanStep() as $step) {
                    $artisanSteps[] = $step;
                }
            } else {
                foreach (O2SwitchModulesArtisanCommandPolicy::allowedArtisanSteps() as $step) {
                    $artisanSteps[] = $step;
                }
            }
        }

        if (! O2SwitchModulesArtisanCommandPolicy::assertPlanArtisanSteps($artisanSteps)) {
            return [
                'plan' => null,
                'code' => 'o2switch_modules_plan_invalid',
                'message' => 'Plan modules : commande Artisan non autorisée.',
            ];
        }

        if ($activatableIds !== [] && ! $dryRunPlanning && $artisanSteps === []) {
            return [
                'plan' => null,
                'code' => 'o2switch_modules_manual_intervention_required',
                'message' => 'Activation live sans commande Artisan allowlistée : intervention manuelle requise.',
            ];
        }

        return [
            'plan' => self::buildPlan(
                $deploymentTarget,
                $moduleIds,
                $noopIds,
                $logicalOperations,
                $artisanSteps,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * @param  list<string>  $requestedModuleIds
     * @param  list<string>  $noopModuleIds
     * @param  list<string>  $logicalOperations
     * @param  list<list<string>>  $artisanCommandSteps
     */
    private static function buildPlan(
        O2SwitchStorageDeploymentTarget $deploymentTarget,
        array $requestedModuleIds,
        array $noopModuleIds,
        array $logicalOperations,
        array $artisanCommandSteps,
    ): O2SwitchModulesPlan {
        $fingerprintPayload = json_encode([
            'requested' => $requestedModuleIds,
            'noop' => $noopModuleIds,
            'logical' => $logicalOperations,
            'artisan' => $artisanCommandSteps,
            'working_directory' => $deploymentTarget->applicationRootAbsolutePath,
        ]);

        return new O2SwitchModulesPlan(
            workingDirectory: $deploymentTarget->applicationRootAbsolutePath,
            requestedModuleIds: $requestedModuleIds,
            noopModuleIds: $noopModuleIds,
            logicalOperations: $logicalOperations,
            artisanCommandSteps: $artisanCommandSteps,
            deploymentTarget: $deploymentTarget,
            modulesPlanFingerprint: hash('sha256', (string) $fingerprintPayload),
        );
    }

    /**
     * @return list<string>
     */
    private static function missingDependencies(ProvisioningContext $context, string $moduleId): array
    {
        $missing = [];

        foreach (GestionModulesCatalog::dependsOnExternalRefs($moduleId) as $refKey) {
            if (($context->externalReferences[$refKey] ?? false) !== true) {
                $missing[] = $refKey;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function logicalOperationsForModule(string $moduleId): array
    {
        $entry = GestionModulesCatalog::find($moduleId);

        if ($entry === null) {
            return [];
        }

        /** @var mixed $ops */
        $ops = $entry['logical_operations'] ?? [];

        if (! is_array($ops)) {
            return [];
        }

        return array_values(array_filter($ops, is_string(...)));
    }
}
