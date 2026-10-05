<?php

namespace Tests\Feature\Provisioning;

use Tests\TestCase;

class ProvisioningRunShowArchitectureTest extends TestCase
{
    public function test_presentation_class_does_not_expose_raw_metadata_field(): void
    {
        $source = file_get_contents(app_path('Support/ProvisioningRunAdminPresentation.php'));

        $this->assertStringContainsString('safePublicSummary', $source);
        $this->assertStringNotContainsString("'metadata'", $source);
    }

    public function test_show_controller_has_no_execution_dependencies(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ProvisioningRunController.php'));

        foreach ([
            'Http::',
            'Guzzle',
            'SSH',
            'o2switch',
            'Dns',
            'Process',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }
}
