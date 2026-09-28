<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallationAccessLabelsTest extends TestCase
{
    public function test_installation_index_vue_uses_no_current_subscription_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Index.vue'));

        $helper = $this->extractAccessDetailLabelHelper($contents, 'resources/js/Pages/Installations/Index.vue');

        $this->assertStringContainsString('Aucun abonnement courant', $helper);
        $this->assertStringContainsString("access.status === 'no_subscription'", $helper);
        $this->assertStringContainsString("access.status === 'terminated'", $helper);
        $this->assertStringNotContainsString('Abonnement terminé', $helper);
    }

    public function test_installation_show_vue_uses_no_current_subscription_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Show.vue'));
        $helper = $this->extractAccessDetailLabelHelper($contents, 'resources/js/Pages/Installations/Show.vue');

        $this->assertStringContainsString('Aucun abonnement courant', $helper);
        $this->assertStringContainsString("access.status === 'no_subscription'", $helper);
        $this->assertStringContainsString("access.status === 'terminated'", $helper);
        $this->assertStringNotContainsString('Abonnement terminé', $helper);
    }

    private function extractAccessDetailLabelHelper(string $contents, string $relativePath): string
    {
        if (! preg_match('/function accessDetailLabel\(access\)\s*\{[\s\S]*?\n\}/', $contents, $matches)) {
            $this->fail("accessDetailLabel helper not found in {$relativePath}");
        }

        return $matches[0];
    }
}
