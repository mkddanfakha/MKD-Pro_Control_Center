<?php

namespace Tests\Feature\Provisioning;

use App\Services\Provisioning\Readiness\InfrastructurePreflightReadinessProofBridge;
use Tests\TestCase;

class InfrastructurePreflightReadinessBridgeArchitectureTest extends TestCase
{
    public function test_bridge_has_no_external_gateway_http_or_database_dependencies(): void
    {
        $source = file_get_contents((new \ReflectionClass(InfrastructurePreflightReadinessProofBridge::class))->getFileName());

        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('ProvisioningInfrastructurePreflight', $source);
        $this->assertStringNotContainsString('->run(', $source);
        $this->assertStringNotContainsString('::create(', $source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('CloudflareDnsPreflightCheck', $source);
        $this->assertStringNotContainsString('O2Switch', $source);
    }
}
