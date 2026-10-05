<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallationAccessLabelsTest extends TestCase
{
    public function test_installation_index_vue_uses_no_active_subscription_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Index.vue'));

        $this->assertStringContainsString('Aucun abonnement actif', $contents);
        $this->assertStringNotContainsString('database_name', $contents);
        $this->assertStringNotContainsString('database_host', $contents);
        $this->assertStringContainsString('Nouvelle installation', $contents);
        $this->assertStringContainsString('admin_urls.create', $contents);
    }

    public function test_installation_show_vue_uses_no_active_subscription_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Show.vue'));

        $this->assertStringContainsString('Aucun abonnement actif', $contents);
        $this->assertStringContainsString('Retour aux installations', $contents);
        $this->assertStringNotContainsString('database_name', $contents);
        $this->assertStringNotContainsString('/installations/create', $contents);
    }

    private function extractAccessDetailLabelHelper(string $contents, string $relativePath): string
    {
        if (! preg_match('/function accessDetailLabel\(access\)\s*\{[\s\S]*?\n\}/', $contents, $matches)) {
            $this->fail("accessDetailLabel helper not found in {$relativePath}");
        }

        return $matches[0];
    }
}
