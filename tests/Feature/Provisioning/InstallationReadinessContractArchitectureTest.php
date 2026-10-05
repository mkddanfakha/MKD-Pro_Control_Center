<?php

namespace Tests\Feature\Provisioning;

use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Readiness\InstallationReadinessAssessor;
use App\Services\Provisioning\Steps\MarkReadyProvisioningStep;
use App\Services\Provisioning\Steps\MarkVerifiedProvisioningStep;
use App\Services\Provisioning\Steps\ReadinessContractStep;
use Tests\TestCase;

/**
 * Le contrat readiness (TASK 380) reste découplé du pipeline et de la persistance.
 */
class InstallationReadinessContractArchitectureTest extends TestCase
{
    public function test_assessor_is_not_registered_as_pipeline_dependency(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringNotContainsString(InstallationReadinessAssessor::class, $source);
    }

    public function test_mark_steps_still_delegate_to_readiness_persistence_adapter_only(): void
    {
        foreach ([MarkVerifiedProvisioningStep::class, MarkReadyProvisioningStep::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringContainsString('InstallationReadinessPersistenceAdapter', $source);
            $this->assertStringNotContainsString('InstallationReadinessAssessor', $source);
        }

        $this->assertTrue(is_subclass_of(MarkReadyProvisioningStep::class, ReadinessContractStep::class));
    }
}
