<?php

namespace App\Services\Provisioning\Steps;

use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use App\DTO\Provisioning\ProvisioningStepResult;
use App\Models\ProvisioningRunStep;

final class ValidateProvisioningStep extends AbstractProductionProvisioningStep
{
    public function __construct()
    {
        parent::__construct(self::canonicalOrderFor(ProvisioningRunStep::STEP_VALIDATE));
    }

    public function stepKey(): string
    {
        return ProvisioningRunStep::STEP_VALIDATE;
    }

    public function execute(ProvisioningContext $context): ProvisioningStepResult
    {
        if (trim($context->installationName) === '') {
            return ProvisioningStepResult::failed(
                $this->stepKey(),
                'validation_installation_name_required',
                'Le nom de l\'installation est requis pour le provisioning.',
                retryable: false,
                category: ProvisioningErrorCategory::Definitive,
            );
        }

        if (trim($context->subdomain) === '') {
            return ProvisioningStepResult::failed(
                $this->stepKey(),
                'validation_subdomain_required',
                'Le sous-domaine est requis pour le provisioning.',
                retryable: false,
                category: ProvisioningErrorCategory::Definitive,
            );
        }

        if ($context->clientId <= 0) {
            return ProvisioningStepResult::failed(
                $this->stepKey(),
                'validation_client_required',
                'Le client associé à l\'installation est requis.',
                retryable: false,
                category: ProvisioningErrorCategory::Definitive,
            );
        }

        return ProvisioningStepResult::succeeded(
            $this->stepKey(),
            outputSummary: [
                'implementation_state' => 'local_validation',
                'installation_id' => $context->installationId,
                'subdomain' => $context->subdomain,
            ],
            metadata: [
                'scope' => 'control_center_local',
            ],
        );
    }
}
