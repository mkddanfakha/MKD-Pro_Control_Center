<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientAccessLabelsTest extends TestCase
{
    public function test_client_show_vue_uses_expected_access_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Clients/Show.vue'));

        $this->assertStringContainsString('Accès autorisé', $contents);
        $this->assertStringContainsString('Accès non autorisé', $contents);
        $helper = $this->accessDetailHelperSource($contents);

        $this->assertStringContainsString('Abonnement suspendu', $helper);
        $this->assertStringContainsString('Période échue', $helper);
        $this->assertStringContainsString('Période de grâce échue', $helper);
        $this->assertStringContainsString('Période de grâce', $helper);
        $this->assertStringContainsString('Abonnement actif', $helper);
        $this->assertStringContainsString('Aucun abonnement courant', $this->accessDetailHelperSource($contents));
        $this->assertStringContainsString("access.status === 'no_subscription'", $helper);
        $this->assertStringContainsString("access.status === 'terminated'", $helper);
        $this->assertStringNotContainsString('Abonnement terminé', $this->accessDetailHelperSource($contents));
        $this->assertStringContainsString('terminated: \'Terminé\'', $contents);
    }

    private function accessDetailHelperSource(string $contents): string
    {
        if (! preg_match('/function accessDetailLabel\(access\)\s*\{[\s\S]*?\n\}/', $contents, $matches)) {
            $this->fail('accessDetailLabel helper not found in Clients/Show.vue');
        }

        return $matches[0];
    }
}
