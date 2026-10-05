<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentShowRefundedCreditDisplayTest extends TestCase
{
    public function test_payment_show_vue_refunded_branch_hides_arithmetic_remaining_credit(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Payments/Show.vue'));

        $this->assertStringContainsString('credit.is_refunded', $contents);
        $this->assertStringContainsString('Non consommable (remboursé)', $contents);

        preg_match(
            '/<template v-if="credit\.is_refunded">[\s\S]*?<\/template>/',
            $contents,
            $matches,
        );

        $this->assertNotEmpty($matches[0] ?? null);
        $this->assertStringNotContainsString('credit.credit_months_remaining', $matches[0]);
    }
}
