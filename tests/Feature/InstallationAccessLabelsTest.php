<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallationAccessLabelsTest extends TestCase
{
    public function test_installation_index_vue_uses_no_current_subscription_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Index.vue'));

        $this->assertStringContainsString('Aucun abonnement courant', $contents);
        $this->assertStringContainsString("access.status === 'no_subscription' || access.status === 'terminated'", $contents);
        $this->assertStringNotContainsString('Abonnement terminé', $contents);
    }

    public function test_installation_show_vue_uses_no_current_subscription_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Show.vue'));

        $this->assertStringContainsString('Aucun abonnement courant', $contents);
        $this->assertStringContainsString("access.status === 'no_subscription' || access.status === 'terminated'", $contents);
        $this->assertStringNotContainsString('Abonnement terminé', $contents);
    }
}
