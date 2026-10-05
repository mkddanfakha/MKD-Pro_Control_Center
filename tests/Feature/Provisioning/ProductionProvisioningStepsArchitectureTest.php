<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProductionProvisioningStepCatalog;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionProvisioningStepsArchitectureTest extends TestCase
{
    public function test_exactly_eighteen_production_step_classes_in_catalog(): void
    {
        $this->assertCount(18, ProductionProvisioningStepCatalog::productionSteps());
        $this->assertCount(18, app(ProvisioningStepRegistry::class)->orderedExecutableSteps());
    }

    public function test_production_registry_matches_canonical_keys(): void
    {
        $keys = array_map(
            fn ($step) => $step->stepKey(),
            app(ProvisioningStepRegistry::class)->orderedExecutableSteps(),
        );

        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $keys);
    }

    public function test_production_step_sources_have_no_external_infrastructure(): void
    {
        $directory = app_path('Services/Provisioning/Steps');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ([
                'Http::',
                'Guzzle',
                'Process::',
                'SSH',
                'o2switch',
                'O2Switch',
                'OVH',
                'Cloudflare',
                'dns_get_record',
                'shell_exec',
                'exec(',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file->getFilename().': '.$forbidden);
            }

        }
    }

    public function test_tests_fakes_are_not_registered_in_production_registry(): void
    {
        foreach (app(ProvisioningStepRegistry::class)->orderedExecutableSteps() as $step) {
            $this->assertStringStartsWith('App\\Services\\Provisioning\\Steps\\', get_class($step));
        }
    }

    public function test_production_steps_depend_on_contracts_not_vendor_sdks(): void
    {
        $directory = app_path('Services/Provisioning/Steps');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $this->assertStringNotContainsString('App\\Services\\Provisioning\\Infrastructure\\Unavailable\\', $source, $file->getFilename());
        }
    }

    public function test_provisioning_execute_is_single_post_route_not_on_installation_show(): void
    {
        $executeRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => in_array('POST', $route->methods(), true)
                && $route->getName() === 'provisioning-runs.execute',
        );

        $this->assertCount(1, $executeRoutes);

        $installationShow = file_get_contents(resource_path('js/Pages/Installations/Show.vue'));
        $this->assertStringNotContainsString('can_execute', $installationShow);
    }
}
