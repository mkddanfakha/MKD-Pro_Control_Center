<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchAdminGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminConfiguration;
use App\Services\Provisioning\Infrastructure\O2Switch\Admin\O2SwitchAdminPlan;

final class FakeO2SwitchAdminGateway implements O2SwitchAdminGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function bootstrapAdmin(
        ProvisioningContext $context,
        O2SwitchAdminConfiguration $configuration,
        O2SwitchAdminPlan $plan,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'operation' => $plan->safeOperationLabel(),
            'admin_plan_fingerprint' => $plan->adminPlanFingerprint,
            'admin_email_normalized' => $plan->bootstrapIdentity->adminEmailNormalized,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_o2switch_admin',
            'Fake gateway par défaut.',
        );
    }
}
