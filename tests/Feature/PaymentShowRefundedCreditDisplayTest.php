<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentShowRefundedCreditDisplayTest extends TestCase
{
    public function test_payment_show_vue_refunded_branch_hides_arithmetic_remaining_credit(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Payments/Show.vue'));

        $refundedBranch = $this->refundedRemainingCreditBranchSource($contents);

        $this->assertStringContainsString('paymentCredit.is_refunded', $refundedBranch);
        $this->assertStringContainsString('—', $refundedBranch);
        $this->assertStringContainsString('Non consommable (remboursé)', $refundedBranch);
        $this->assertStringNotContainsString('paymentCredit.credit_months_remaining', $refundedBranch);
        $this->assertStringNotContainsString('remainingMonthsLabel', $refundedBranch);
    }

    private function refundedRemainingCreditBranchSource(string $contents): string
    {
        if (! preg_match(
            '/<template v-else-if="paymentCredit\.is_refunded">\s*([\s\S]*?)<\/template>/',
            $contents,
            $matches,
        )) {
            $this->fail('Refunded remaining-credit branch not found in Subscriptions/Payments/Show.vue');
        }

        return $matches[0];
    }
}
