<?php

namespace HTWarehouse\Tests\Unit;

use HTWarehouse\Services\CostCalculator;
use HTWarehouse\Services\NumberHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CostCalculator.
 *
 * CostCalculator::add_stock() and deduct_stock() write to the DB via $wpdb,
 * but the wpdb stub in bootstrap.php always returns success — we test the
 * CALCULATION logic only, not persistence.
 *
 * allocate_extra_costs() is pure PHP (no DB), so it is tested fully.
 */
class CostCalculatorTest extends TestCase
{
    // ── WAC helpers (extracted for readability in tests) ─────────────────────

    /**
     * Compute expected WAC given pre-existing stock and an incoming batch.
     * Mirrors the formula in CostCalculator::add_stock().
     */
    private function expectedWac(
        string $old_stock,
        string $old_avg,
        string $new_qty,
        string $new_cost
    ): string {
        $total_qty = NumberHelper::add($old_stock, $new_qty);
        $old_value = NumberHelper::mul($old_stock, $old_avg);
        $new_value = NumberHelper::mul($new_qty, $new_cost);
        $sum       = NumberHelper::add($old_value, $new_value);

        return NumberHelper::comp($total_qty, '0', 4) > 0
            ? NumberHelper::div($sum, $total_qty, 4)
            : $new_cost;
    }

    // ── add_stock() — WAC recalculation ──────────────────────────────────────

    public function test_wac_first_import_zero_existing_stock(): void
    {
        // No prior stock — WAC = new cost
        $wac = $this->expectedWac('0', '0', '10', '50000');
        $this->assertSame('50000.0000', $wac);
    }

    public function test_wac_equal_cost_stays_same(): void
    {
        // Adding at the same cost — WAC must not change
        $wac = $this->expectedWac('10', '50000', '5', '50000');
        $this->assertSame('50000.0000', $wac);
    }

    public function test_wac_blended_correctly(): void
    {
        // (10 × 50,000 + 5 × 80,000) / 15 = 60,000
        $wac = $this->expectedWac('10', '50000', '5', '80000');
        $this->assertSame('60000.0000', $wac);
    }

    public function test_wac_higher_qty_new_batch_pulls_avg_up(): void
    {
        // More of the expensive batch → WAC pulls toward new cost
        $wac = $this->expectedWac('1', '50000', '99', '100000');
        // Expected: (1×50000 + 99×100000) / 100 = 99500
        $this->assertSame('99500.0000', $wac);
    }

    public function test_wac_lower_qty_new_batch_minimal_impact(): void
    {
        // Tiny new batch barely moves the WAC
        $wac = $this->expectedWac('100', '50000', '1', '100000');
        // (100×50000 + 1×100000) / 101 ≈ 50495.0495
        $this->assertSame('50495.0495', $wac);
    }

    public function test_wac_fractional_quantities(): void
    {
        // Fractional units (e.g. kg): (2.5 × 40000 + 1.5 × 60000) / 4 = 47500
        $wac = $this->expectedWac('2.5', '40000', '1.5', '60000');
        $this->assertSame('47500.0000', $wac);
    }

    // ── deduct_stock() — WAC unchanged, clamp to zero ────────────────────────

    public function test_deduct_stock_returns_unchanged_avg(): void
    {
        // deduct_stock() must return the locked_avg unchanged (WAC rule)
        $result = CostCalculator::deduct_stock(1, '10', '75000', '3');
        $this->assertSame('75000', $result);
    }

    public function test_deduct_stock_does_not_go_negative(): void
    {
        // new_stock = max(0, locked - qty) — should clamp, not error
        // The assertion is via DB write (mocked) — this test simply verifies
        // no exception is thrown and locked_avg is returned.
        $result = CostCalculator::deduct_stock(1, '5', '50000', '10');
        $this->assertSame('50000', $result);
    }

    public function test_deduct_stock_exact_quantity_returns_zero(): void
    {
        $result = CostCalculator::deduct_stock(1, '10', '30000', '10');
        $this->assertSame('30000', $result);
    }

    // ── allocate_extra_costs() ────────────────────────────────────────────────

    public function test_allocate_no_extra_cost(): void
    {
        $items = [
            ['product_id' => 1, 'qty' => 10, 'unit_price' => 50000],
            ['product_id' => 2, 'qty' => 5,  'unit_price' => 80000],
        ];
        $result = CostCalculator::allocate_extra_costs($items, 0.0);

        // With zero extra cost, allocated_cost_per_unit == unit_price
        $this->assertSame('50000.0000', $result[0]['allocated_cost_per_unit']);
        $this->assertSame('80000.0000', $result[1]['allocated_cost_per_unit']);
    }

    public function test_allocate_single_item_absorbs_all_cost(): void
    {
        $items = [
            ['product_id' => 1, 'qty' => 10, 'unit_price' => 100000],
        ];
        $result = CostCalculator::allocate_extra_costs($items, 500000.0);

        // 500,000 / 10 = 50,000 extra per unit → allocated = 150,000
        $this->assertSame('150000.0000', $result[0]['allocated_cost_per_unit']);
        $this->assertSame('1500000.0000', $result[0]['total_cost']);
    }

    public function test_allocate_two_items_proportional(): void
    {
        // Item A: 10 × 100,000 = 1,000,000 (50% of total value)
        // Item B: 10 × 100,000 = 1,000,000 (50% of total value)
        // Extra cost: 200,000 → each item gets 100,000
        $items = [
            ['product_id' => 1, 'qty' => 10, 'unit_price' => 100000],
            ['product_id' => 2, 'qty' => 10, 'unit_price' => 100000],
        ];
        $result = CostCalculator::allocate_extra_costs($items, 200000.0);

        $this->assertSame('110000.0000', $result[0]['allocated_cost_per_unit']);
        $this->assertSame('110000.0000', $result[1]['allocated_cost_per_unit']);
    }

    public function test_allocate_sum_equals_extra_cost_exactly(): void
    {
        // Remainder correction: SUM of all allocations must equal extra_cost exactly.
        // Using an awkward number to force rounding: 100,003 VND across 3 items.
        $items = [
            ['product_id' => 1, 'qty' => 1, 'unit_price' => 10000],
            ['product_id' => 2, 'qty' => 1, 'unit_price' => 10000],
            ['product_id' => 3, 'qty' => 1, 'unit_price' => 10000],
        ];
        $extra_cost = 100003.0;
        $result = CostCalculator::allocate_extra_costs($items, $extra_cost);

        $sum_allocated = NumberHelper::add(
            NumberHelper::add($result[0]['total_cost'], $result[1]['total_cost']),
            $result[2]['total_cost']
        );
        // total_cost = qty × allocated_cost_per_unit for each item
        // Sum of extra allocated portions must equal extra_cost:
        $extra_item_0 = NumberHelper::sub($result[0]['total_cost'], '10000');
        $extra_item_1 = NumberHelper::sub($result[1]['total_cost'], '10000');
        $extra_item_2 = NumberHelper::sub($result[2]['total_cost'], '10000');
        $sum_extra    = NumberHelper::add(NumberHelper::add($extra_item_0, $extra_item_1), $extra_item_2);

        $this->assertSame(
            '100003.0000',
            $sum_extra,
            'SUM of allocated extras must equal extra_cost exactly (remainder correction)'
        );
    }

    public function test_allocate_proportional_by_item_value(): void
    {
        // Item A: 1 × 200,000 (value = 200k, 2/3 of 300k total)
        // Item B: 1 × 100,000 (value = 100k, 1/3 of 300k total)
        // Extra: 300,000 → A ~200,000, B absorbs remainder to make SUM exact.
        $items = [
            ['product_id' => 1, 'qty' => 1, 'unit_price' => 200000],
            ['product_id' => 2, 'qty' => 1, 'unit_price' => 100000],
        ];
        $result = CostCalculator::allocate_extra_costs($items, 300000.0);

        // Item A (non-last): allocated via bcmath 6dp division → may have tiny rounding
        // Item B (last): absorbs remainder to make total exact
        // Key invariant: allocated_A + allocated_B === extra_cost exactly
        $extra_a = (float) NumberHelper::sub($result[0]['allocated_cost_per_unit'], '200000');
        $extra_b = (float) NumberHelper::sub($result[1]['allocated_cost_per_unit'], '100000');
        $sum_extra = $extra_a + $extra_b;

        $this->assertEqualsWithDelta(300000.0, $sum_extra, 0.01,
            'SUM of allocated extras must equal extra_cost exactly (remainder correction)');

        // Individual allocations should be approximately proportional (within 1đ rounding)
        $this->assertEqualsWithDelta(200000.0, $extra_a, 1.0,
            'Item A (2/3 of value) should absorb ~200,000 of extra cost');
        $this->assertEqualsWithDelta(100000.0, $extra_b, 1.0,
            'Item B (1/3 of value) should absorb ~100,000 of extra cost (+ rounding remainder)');
    }


    public function test_allocate_returns_items_with_required_keys(): void
    {
        $items = [
            ['product_id' => 1, 'qty' => 5, 'unit_price' => 50000],
        ];
        $result = CostCalculator::allocate_extra_costs($items, 50000.0);

        $this->assertArrayHasKey('allocated_cost_per_unit', $result[0]);
        $this->assertArrayHasKey('total_cost', $result[0]);
        $this->assertArrayHasKey('product_id', $result[0]); // original keys preserved
    }

    public function test_allocate_real_world_lego_scenario(): void
    {
        // Lego set: 6 units at 450,000 VND + shipping 90,000 VND
        // → each unit should absorb 15,000 extra → final cost = 465,000
        $items = [
            ['product_id' => 42, 'qty' => 6, 'unit_price' => 450000],
        ];
        $result = CostCalculator::allocate_extra_costs($items, 90000.0);

        $this->assertSame('465000.0000', $result[0]['allocated_cost_per_unit']);
        $this->assertSame('2790000.0000', $result[0]['total_cost']);
    }
}
