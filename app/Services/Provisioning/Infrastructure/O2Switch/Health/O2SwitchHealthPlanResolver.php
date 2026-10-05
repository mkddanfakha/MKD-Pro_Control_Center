<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

use App\Services\Provisioning\Gestion\GestionHealthCheckCatalog;
use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchHealthPlanResolver
{
    /**
     * @return array{
     *     plan: O2SwitchHealthPlan|null,
     *     code: string|null,
     *     message: string|null
     * }
     */
    public static function resolve(
        O2SwitchStorageDeploymentTarget $deploymentTarget,
        ?string $applicationPublicBaseUrl,
    ): array {
        $items = [];

        foreach (GestionHealthCheckCatalog::definitions() as $definition) {
            $item = self::definitionToItem($definition);
            if ($item === null) {
                return [
                    'plan' => null,
                    'code' => 'o2switch_health_plan_invalid',
                    'message' => 'Définition de contrôle health invalide dans la configuration.',
                ];
            }

            if ($item->type === 'artisan' && $item->artisanArgv !== null) {
                if (! O2SwitchHealthArtisanCommandPolicy::isAllowedStep($item->artisanArgv)) {
                    return [
                        'plan' => null,
                        'code' => 'o2switch_health_plan_invalid',
                        'message' => sprintf(
                            'Commande Artisan non autorisée pour le contrôle %s.',
                            $item->checkKey,
                        ),
                    ];
                }
            }

            $items[] = $item;
        }

        if (! self::dependenciesAreValid($items)) {
            return [
                'plan' => null,
                'code' => 'o2switch_health_plan_invalid',
                'message' => 'Dépendances de contrôles health incohérentes.',
            ];
        }

        $fingerprintPayload = json_encode([
            'checks' => array_map(
                static fn (O2SwitchHealthCheckPlanItem $item): array => $item->toSafePlanArray(),
                $items,
            ),
            'working_directory' => $deploymentTarget->applicationRootAbsolutePath,
            'application_public_base_url' => $applicationPublicBaseUrl,
        ]);

        return [
            'plan' => new O2SwitchHealthPlan(
                workingDirectory: $deploymentTarget->applicationRootAbsolutePath,
                applicationPublicBaseUrl: $applicationPublicBaseUrl,
                checks: $items,
                deploymentTarget: $deploymentTarget,
                healthPlanFingerprint: hash('sha256', (string) $fingerprintPayload),
            ),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function definitionToItem(array $definition): ?O2SwitchHealthCheckPlanItem
    {
        $checkKey = $definition['check_key'] ?? null;
        if (! is_string($checkKey) || $checkKey === '') {
            return null;
        }

        $type = $definition['type'] ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }

        $scope = $definition['scope'] ?? 'health';
        if (! is_string($scope)) {
            return null;
        }

        /** @var mixed $depends */
        $depends = $definition['depends_on'] ?? [];
        $dependsOn = is_array($depends)
            ? array_values(array_filter($depends, is_string(...)))
            : [];

        $artisanArgv = null;
        if ($type === 'artisan') {
            $argvKey = $definition['artisan_argv_config_key'] ?? null;
            if (is_string($argvKey)) {
                /** @var mixed $argv */
                $argv = config($argvKey, []);
                $artisanArgv = is_array($argv) ? $argv : null;
            }
        }

        $httpPath = null;
        if ($type === 'http') {
            $path = $definition['http_path'] ?? config('provisioning.gestion.health_laravel_up_path', '/up');
            $httpPath = is_string($path) ? $path : '/up';
        }

        $relativePath = null;
        if ($type === 'filesystem_relative') {
            $path = $definition['relative_path'] ?? null;
            $relativePath = is_string($path) ? $path : null;
        }

        $externalReferenceKey = null;
        if ($type === 'external_reference') {
            $ref = $definition['external_reference_key'] ?? null;
            $externalReferenceKey = is_string($ref) ? $ref : null;
        }

        return new O2SwitchHealthCheckPlanItem(
            checkKey: $checkKey,
            order: (int) ($definition['order'] ?? 0),
            type: $type,
            scope: $scope,
            category: is_string($definition['category'] ?? null) ? (string) $definition['category'] : 'P',
            expectedOutcome: is_string($definition['expected_outcome'] ?? null)
                ? (string) $definition['expected_outcome']
                : 'ok',
            logicalTimeoutSeconds: (int) ($definition['logical_timeout_seconds'] ?? 30),
            dependsOnCheckKeys: $dependsOn,
            httpPath: $httpPath,
            relativePath: $relativePath,
            externalReferenceKey: $externalReferenceKey,
            artisanArgv: $artisanArgv,
        );
    }

    /**
     * @param  list<O2SwitchHealthCheckPlanItem>  $items
     */
    private static function dependenciesAreValid(array $items): bool
    {
        $keys = array_map(
            static fn (O2SwitchHealthCheckPlanItem $item): string => $item->checkKey,
            $items,
        );

        foreach ($items as $item) {
            foreach ($item->dependsOnCheckKeys as $dependency) {
                if (! in_array($dependency, $keys, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
