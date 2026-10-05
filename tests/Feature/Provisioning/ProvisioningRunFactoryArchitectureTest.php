<?php

namespace Tests\Feature\Provisioning;

use Tests\TestCase;

class ProvisioningRunFactoryArchitectureTest extends TestCase
{
    public function test_factory_has_no_external_infrastructure_dependencies(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningRunFactory.php'));

        foreach ([
            'o2switch',
            'O2Switch',
            'Ssh',
            'SSH',
            'Dns',
            'Http',
            'Guzzle',
            'Vault',
            'Process',
            'schedule',
            'runPersisted',
            'ProvisioningPipeline',
            'ProvisioningPersistedRunOrchestrator',
            'ProvisioningRunStepStateService',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    public function test_factory_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\Provisioning\ProvisioningRunFactory::class));
    }
}
