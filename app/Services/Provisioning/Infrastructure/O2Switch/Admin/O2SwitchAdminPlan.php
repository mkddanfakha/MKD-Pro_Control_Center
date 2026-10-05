<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

use App\Services\Provisioning\Infrastructure\O2Switch\Storage\O2SwitchStorageDeploymentTarget;

final class O2SwitchAdminPlan
{
    /**
     * @param  list<string>  $logicalOperations
     * @param  list<list<string>>  $artisanCommandSteps
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $logicalOperations,
        public readonly array $artisanCommandSteps,
        public readonly O2SwitchAdminBootstrapIdentity $bootstrapIdentity,
        public readonly O2SwitchStorageDeploymentTarget $deploymentTarget,
        public readonly string $adminPlanFingerprint,
        public readonly string $adminRoleValue,
        public readonly string $adminUniqueIdentityField,
    ) {}

    public function safeOperationLabel(): string
    {
        return 'gestion_admin_bootstrap';
    }
}
