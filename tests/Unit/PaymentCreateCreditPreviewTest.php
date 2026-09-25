<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Règles d’aperçu crédit alignées sur Subscriptions/Payments/Create.vue (affichage uniquement).
 */
class PaymentCreateCreditPreviewTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    private function preview(?int $monthlyAmount, mixed $paymentAmount): ?array
    {
        if ($monthlyAmount === null || $monthlyAmount <= 0) {
            return null;
        }

        $parsed = $this->parsePositiveIntegerAmount($paymentAmount);

        if ($parsed === null) {
            return null;
        }

        if ($parsed % $monthlyAmount !== 0) {
            return ['type' => 'indivisible'];
        }

        return [
            'type' => 'match',
            'months' => (int) ($parsed / $monthlyAmount),
        ];
    }

    private function parsePositiveIntegerAmount(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $numeric = (int) $value;

        if ($numeric <= 0) {
            return null;
        }

        if (is_float($value) && $value != $numeric) {
            return null;
        }

        return $numeric;
    }

    #[DataProvider('matchingAmountProvider')]
    public function test_matching_amounts_show_month_count(int $amount, int $expectedMonths): void
    {
        $result = $this->preview(15000, $amount);

        $this->assertIsArray($result);
        $this->assertSame('match', $result['type']);
        $this->assertSame($expectedMonths, $result['months']);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function matchingAmountProvider(): array
    {
        return [
            '15000 one month' => [15000, 1],
            '45000 three months' => [45000, 3],
            '90000 six months' => [90000, 6],
            '180000 twelve months' => [180000, 12],
        ];
    }

    public function test_non_divisible_amount_shows_indivisible_state(): void
    {
        $result = $this->preview(15000, 20000);

        $this->assertSame(['type' => 'indivisible'], $result);
    }

    public function test_empty_amount_shows_no_preview(): void
    {
        $this->assertNull($this->preview(15000, ''));
    }

    public function test_missing_subscription_monthly_shows_no_preview(): void
    {
        $this->assertNull($this->preview(null, 15000));
    }

    public function test_zero_monthly_shows_no_preview(): void
    {
        $this->assertNull($this->preview(0, 15000));
    }

    public function test_store_payload_excludes_client_credit_fields(): void
    {
        $payload = [
            'subscription_id' => 1,
            'amount' => 45000,
            'currency' => 'XOF',
            'status' => 'paid',
            'payment_method' => 'wave',
            'reference' => 'REF-TEST',
        ];

        $this->assertArrayNotHasKey('credit_months_purchased', $payload);
        $this->assertArrayNotHasKey('monthly_unit_amount', $payload);
    }
}
