<?php

namespace Tests\Feature\Provisioning;

use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\Providers\ProvisioningServiceProvider;
use App\Services\Provisioning\Infrastructure\O2Switch\O2SwitchHostingAdapter;
use App\Services\Provisioning\Steps\HostingProvisioningStep;
use Tests\TestCase;

class O2SwitchHostingArchitectureTest extends TestCase
{
    public function test_provisioning_o2switch_hosting_disabled_by_default_in_config(): void
    {
        $this->assertFalse(config('provisioning.o2switch.hosting.enabled'));
        $this->assertFalse(config('provisioning.o2switch.hosting.dry_run'));
    }

    public function test_production_binds_hosting_adapter_behind_contract_not_local(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProvisioningServiceProvider::class))->getFileName());

        $this->assertStringContainsString(O2SwitchHostingAdapter::class, $source);
        $this->assertStringNotContainsString('Infrastructure\\Local\\', $source);

        $adapter = app(HostingSpaceAdapter::class);
        $this->assertInstanceOf(O2SwitchHostingAdapter::class, $adapter);
    }

    public function test_hosting_step_does_not_reference_o2switch_directly(): void
    {
        $source = file_get_contents((new \ReflectionClass(HostingProvisioningStep::class))->getFileName());

        $this->assertStringNotContainsString('o2switch', strtolower($source));
        $this->assertStringNotContainsString('O2Switch', $source);
        $this->assertStringNotContainsString('Http::', $source);
    }

    public function test_o2switch_hosting_sources_contain_no_hardcoded_secrets_or_invented_urls(): void
    {
        $directory = app_path('Services/Provisioning/Infrastructure/O2Switch');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Deploy'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Environment'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Dependencies'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Build'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Migrate'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Storage'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Cache'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Admin'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Health'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ([
                'https://',
                'http://',
                'api.o2switch',
                'password=',
                'Bearer ',
                'sk_live',
                'Http::',
                'Guzzle',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file->getFilename().': '.$forbidden);
            }
        }
    }

    public function test_env_example_documents_o2switch_flags_without_secret_values(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('PROVISIONING_O2SWITCH_HOSTING_ENABLED=false', $example);
        $this->assertStringContainsString('PROVISIONING_O2SWITCH_HOSTING_DRY_RUN=false', $example);
        $this->assertStringNotContainsString('PROVISIONING_O2SWITCH_API_TOKEN=real', $example);
    }
}
