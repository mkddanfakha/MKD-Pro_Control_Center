<?php

namespace Tests\Feature;

use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningPipeline;
use App\Services\Provisioning\ProvisioningStepRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ProvisioningPipelineArchitectureTest extends TestCase
{
    public function test_eighteen_step_keys_match_canonical_contract(): void
    {
        $registry = new ProvisioningStepRegistry;
        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $registry->orderedStepKeys());
    }

    public function test_pipeline_contract_classes_exist_without_external_engine(): void
    {
        $this->assertTrue(interface_exists(\App\Contracts\Provisioning\ProvisioningStep::class));
        $this->assertTrue(class_exists(ProvisioningPipeline::class));
        $this->assertTrue(class_exists(ProvisioningStepRegistry::class));
        $this->assertTrue(class_exists(\App\DTO\Provisioning\ProvisioningContext::class));
        $this->assertTrue(class_exists(\App\DTO\Provisioning\ProvisioningStepResult::class));

        $this->assertFalse(class_exists(\App\Services\ProvisioningService::class));
        $this->assertFalse(class_exists(\App\Services\SshProvisioningClient::class));
        $this->assertFalse(class_exists(\App\Services\DnsProvisioningService::class));
        $this->assertFalse(class_exists(\App\Services\O2SwitchService::class));
        $this->assertFalse(class_exists(\App\Services\MkdProGestionApiClient::class));
        $this->assertFalse(class_exists(\App\Services\VaultSecretProvider::class));
        $this->assertFalse(class_exists(\App\Jobs\ProvisioningJob::class));
        $this->assertFalse(class_exists(\App\Http\Controllers\ProvisioningController::class));
    }

    public function test_no_provisioning_routes_or_scheduler_entries(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains((string) $route->getName(), 'provisioning'),
        );
        $this->assertCount(3, $routes);
        $this->assertNotNull($routes->firstWhere(fn ($route) => $route->getName() === 'installations.provisioning-runs.store'));
        $this->assertNotNull($routes->firstWhere(fn ($route) => $route->getName() === 'provisioning-runs.show'));
        $this->assertNotNull($routes->firstWhere(fn ($route) => $route->getName() === 'provisioning-runs.execute'));
        $provisioningSchedule = collect(Schedule::events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'provisioning'));
        $this->assertCount(0, $provisioningSchedule);
    }

    public function test_pipeline_contract_document_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/control-center-provisioning-pipeline-contract.md'));
    }

    public function test_pipeline_orchestrator_has_no_external_provisioning_dependencies(): void
    {
        $pipelineSource = file_get_contents(app_path('Services/Provisioning/ProvisioningPipeline.php'));
        $registrySource = file_get_contents(app_path('Services/Provisioning/ProvisioningStepRegistry.php'));

        foreach (['SSH', 'o2switch', 'Dns', 'DNS', 'Vault', 'MkdProGestion'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $pipelineSource);
            $this->assertStringNotContainsString($forbidden, $registrySource);
        }
    }

    public function test_production_app_does_not_reference_test_fake_provisioning_steps(): void
    {
        $appPhpFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($appPhpFiles as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('Tests\\Fakes\\Provisioning', $contents, $file->getPathname());
            $this->assertStringNotContainsString('FakeSuccessfulStep', $contents, $file->getPathname());
        }
    }

    public function test_pure_run_method_does_not_invoke_state_services(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningPipeline.php'));

        $runMethod = $this->extractFunctionBody($source, 'public function run(');

        $this->assertStringNotContainsString('ProvisioningRunStateService', $runMethod);
        $this->assertStringNotContainsString('ProvisioningRunStepStateService', $runMethod);
    }

    public function test_persisted_orchestrator_delegates_transitions_to_state_services(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningPersistedRunOrchestrator.php'));

        $this->assertStringContainsString('ProvisioningRunStateService', $source);
        $this->assertStringContainsString('ProvisioningRunStepStateService', $source);
        $this->assertStringNotContainsString('SSH', $source);
        $this->assertStringNotContainsString('o2switch', $source);
    }

    private function extractFunctionBody(string $source, string $signatureStart): string
    {
        $start = strpos($source, $signatureStart);
        $this->assertNotFalse($start);

        $brace = strpos($source, '{', $start);
        $this->assertNotFalse($brace);

        $depth = 0;
        $length = strlen($source);

        for ($i = $brace; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail('Corps de fonction introuvable.');

        return '';
    }
}
