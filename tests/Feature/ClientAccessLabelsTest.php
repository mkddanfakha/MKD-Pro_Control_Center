<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientAccessLabelsTest extends TestCase
{
    public function test_client_show_vue_uses_admin_status_labels_and_read_only_copy(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Clients/Show.vue'));

        $this->assertStringContainsString('Retour aux clients', $contents);
        $this->assertStringContainsString('Modifier', $contents);
        $this->assertStringContainsString('Ouvrir la fiche', $contents);
        $this->assertStringContainsString('grace_period: \'Période de grâce\'', $contents);
        $this->assertStringContainsString('terminated: \'Terminé\'', $contents);
        $this->assertStringContainsString('Lecture seule — aucun envoi ni retraitement depuis cette page.', $contents);
        $this->assertStringNotContainsString('installationOverviews', $contents);
        $this->assertStringNotContainsString('accessDetailLabel', $contents);
    }
}
