<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentIndexDeleteUiGuardTest extends TestCase
{
    public function test_index_vue_uses_consumptions_count_for_delete_guard(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Payments/Index.vue'));

        $this->assertStringContainsString('consumptions_count', $contents);
        $this->assertStringContainsString('canDeletePayment', $contents);
        $this->assertStringContainsString('Crédit consommé', $contents);
        $this->assertStringContainsString('!canDeletePayment(payment)', $contents);
        $this->assertStringContainsString('openDeleteConfirm(payment)', $contents);

        $openDeleteHelper = $this->extractFunction($contents, 'openDeleteConfirm');

        $this->assertStringContainsString('canDeletePayment(payment)', $openDeleteHelper);
    }

    private function extractFunction(string $contents, string $functionName): string
    {
        $pattern = '/function '.$functionName.'\([^)]*\)\s*\{[\s\S]*?\n\}/';

        if (! preg_match($pattern, $contents, $matches)) {
            $this->fail("Function {$functionName} not found in Payments/Index.vue");
        }

        return $matches[0];
    }
}
