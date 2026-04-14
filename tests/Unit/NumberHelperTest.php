<?php

namespace HTWarehouse\Tests\Unit;

use HTWarehouse\Services\NumberHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NumberHelper.
 *
 * Verifies bcmath-backed arithmetic, comparison, and normalization helpers.
 * These tests must pass on any environment — bcmath is required.
 */
class NumberHelperTest extends TestCase
{
    // ── add() ────────────────────────────────────────────────────────────────

    public function test_add_integers(): void
    {
        $this->assertSame('3.0000', NumberHelper::add('1', '2'));
    }

    public function test_add_decimal_strings(): void
    {
        $this->assertSame('0.3000', NumberHelper::add('0.1', '0.2'));
    }

    public function test_add_large_numbers(): void
    {
        // Float arithmetic: 999999999.99 + 0.01 can lose precision
        $this->assertSame('1000000000.0000', NumberHelper::add('999999999.9999', '0.0001'));
    }

    public function test_add_zero(): void
    {
        $this->assertSame('5.5000', NumberHelper::add('5.5', '0'));
    }

    // ── sub() ────────────────────────────────────────────────────────────────

    public function test_sub_basic(): void
    {
        $this->assertSame('7.0000', NumberHelper::sub('10', '3'));
    }

    public function test_sub_results_in_negative(): void
    {
        // sub() must support negative results (caller decides whether to clamp)
        $this->assertSame('-1.0000', NumberHelper::sub('2', '3'));
    }

    public function test_sub_decimal_precision(): void
    {
        // Classic float problem: 1.0 - 0.9 ≠ 0.1 in native float
        $result = NumberHelper::sub('1.0', '0.9');
        $this->assertSame('0.1000', $result);
    }

    // ── mul() ────────────────────────────────────────────────────────────────

    public function test_mul_basic(): void
    {
        $this->assertSame('6.0000', NumberHelper::mul('2', '3'));
    }

    public function test_mul_decimal(): void
    {
        $this->assertSame('10.5000', NumberHelper::mul('3.5', '3'));
    }

    public function test_mul_by_zero(): void
    {
        $this->assertSame('0.0000', NumberHelper::mul('99999', '0'));
    }

    public function test_mul_unit_price_qty(): void
    {
        // 150,000 VND × 6 units = 900,000 VND
        $this->assertSame('900000.0000', NumberHelper::mul('150000', '6'));
    }

    // ── div() ────────────────────────────────────────────────────────────────

    public function test_div_basic(): void
    {
        $this->assertSame('2.5000', NumberHelper::div('5', '2'));
    }

    public function test_div_custom_scale(): void
    {
        $this->assertSame('0.333333', NumberHelper::div('1', '3', 6));
    }

    public function test_div_wac_scenario(): void
    {
        // WAC: (10 × 50,000 + 5 × 80,000) / 15 = 60,000
        $numerator = NumberHelper::add(
            NumberHelper::mul('10', '50000'),
            NumberHelper::mul('5', '80000')
        );
        $result = NumberHelper::div($numerator, '15', 4);
        $this->assertSame('60000.0000', $result);
    }

    // ── comp() ───────────────────────────────────────────────────────────────

    public function test_comp_greater(): void
    {
        $this->assertSame(1, NumberHelper::comp('5', '3'));
    }

    public function test_comp_less(): void
    {
        $this->assertSame(-1, NumberHelper::comp('3', '5'));
    }

    public function test_comp_equal(): void
    {
        $this->assertSame(0, NumberHelper::comp('3.0000', '3'));
    }

    // ── isZeroOrNegative() ───────────────────────────────────────────────────

    public function test_is_zero_or_negative_with_zero(): void
    {
        $this->assertTrue(NumberHelper::isZeroOrNegative('0'));
        $this->assertTrue(NumberHelper::isZeroOrNegative('0.0000'));
    }

    public function test_is_zero_or_negative_with_negative(): void
    {
        $this->assertTrue(NumberHelper::isZeroOrNegative('-1'));
        $this->assertTrue(NumberHelper::isZeroOrNegative('-0.0001'));
    }

    public function test_is_zero_or_negative_with_positive(): void
    {
        $this->assertFalse(NumberHelper::isZeroOrNegative('0.01'));
        $this->assertFalse(NumberHelper::isZeroOrNegative('1'));
    }

    // ── isPositive() ─────────────────────────────────────────────────────────

    public function test_is_positive_with_positive(): void
    {
        $this->assertTrue(NumberHelper::isPositive('0.01'));
        $this->assertTrue(NumberHelper::isPositive('100000'));
    }

    public function test_is_positive_with_zero_or_negative(): void
    {
        $this->assertFalse(NumberHelper::isPositive('0'));
        $this->assertFalse(NumberHelper::isPositive('-5'));
    }

    // ── normalize() ──────────────────────────────────────────────────────────

    public function test_normalize_trims_trailing_zeros(): void
    {
        $this->assertSame('1.5', NumberHelper::normalize('1.5000'));
        $this->assertSame('100', NumberHelper::normalize('100.0000'));
        $this->assertSame('0', NumberHelper::normalize('0.0000'));
    }

    public function test_normalize_integer_unchanged(): void
    {
        $this->assertSame('100', NumberHelper::normalize('100'));
    }
}
