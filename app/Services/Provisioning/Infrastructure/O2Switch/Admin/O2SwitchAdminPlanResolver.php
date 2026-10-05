<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchAdminPlanResolver
{
    /**
     * @return array{plan: ?O2SwitchAdminPlan, code: ?string, message: ?string}
     */
    public static function resolve(
        O2SwitchStorageDeploymentTarget $deploymentTarget,
        O2SwitchAdminBootstrapIdentity $identity,
    ): array {
        /** @var list<string> $logical */
        $logical = config('provisioning.gestion.admin_bootstrap_logical_operations', []);
        if ($logical === []) {
            return [
                'plan' => null,
                'code' => 'o2switch_admin_not_configured',
                'message' => 'Opérations logiques admin Gestion absentes.',
            ];
        }

        $artisanSteps = O2SwitchAdminArtisanCommandPolicy::allowedArtisanSteps();
        if (! O2SwitchAdminArtisanCommandPolicy::assertPlanArtisanSteps($artisanSteps)) {
            return [
                'plan' => null,
                'code' => 'o2switch_admin_not_configured',
                'message' => 'Étapes Artisan admin non autorisées.',
            ];
        }

        $adminPlanFingerprint = hash('sha256', json_encode([
            'logical' => $logical,
            'artisan' => $artisanSteps,
            'identity_fingerprint' => $identity->identityFingerprint,
            'deployment_target_fingerprint' => $deploymentTarget->targetFingerprint,
        ], JSON_THROW_ON_ERROR));

        return [
            'plan' => new O2SwitchAdminPlan(
                workingDirectory: $deploymentTarget->applicationRootAbsolutePath,
                logicalOperations: $logical,
                artisanCommandSteps: $artisanSteps,
                bootstrapIdentity: $identity,
                deploymentTarget: $deploymentTarget,
                adminPlanFingerprint: $adminPlanFingerprint,
                adminRoleValue: (string) config('provisioning.gestion.admin_role_value', 'admin'),
                adminUniqueIdentityField: (string) config('provisioning.gestion.admin_unique_identity_field', 'email'),
            ),
            'code' => null,
            'message' => null,
        ];
    }
}
