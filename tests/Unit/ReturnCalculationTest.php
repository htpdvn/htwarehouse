<?php

namespace HTWarehouse\Tests\Unit;

use HTWarehouse\Services\NumberHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for return-order financial calculations.
 *
 * Tests two distinct areas:
 *
 * 1. WAC RECALCULATION ON RETURN
 *    When goods are returned, stock is restored and avg_cost is recalculated
 *    treating the return as a "re-import" at the original COGS price.
 *    Formula (mirrors CostCalculator::add_stock but uses cogs_back):
 *      new_avg = (old_stock × old_avg + qty_back × cogs_back) / (old_stock + qty_back)
 *
 * 2. EXPORT ORDER STATUS AFTER RETURN
 *    After confirming returns, recalculate_export_order() determines whether
 *    the export order should be 'confirmed', 'partial_return', or 'fully_returned'
 *    by checking each line item individually.
 */
class ReturnCalculationTest extends TestCase
{
    // ── WAC helpers ──────────────────────────────────────────────────────────

    /**
     * Mirrors the WAC recalculation from ajax_confirm_return():
     *   new_stock   = old_stock + qty_back
     *   new_avg     = (old_stock × old_avg + qty_back × cogs_back) / new_stock
     *
     * If new_stock = 0, avg_cost is left unchanged.
     */
    private function calcReturnWac(
        float $old_stock,
        float $old_avg,
        float $qty_back,
        float $cogs_back
    ): array {
        $new_stock = NumberHelper::add((string) $old_stock, (string) $qty_back);

        if ((float) $new_stock > 0) {
            $numerator    = NumberHelper::add(
                NumberHelper::mul((string) $old_stock, (string) $old_avg),
                NumberHelper::mul((string) $qty_back,  (string) $cogs_back)
            );
            $new_avg = NumberHelper::div($numerator, $new_stock, 4);
        } else {
            $new_avg = (string) $old_avg;
        }

        return [
            'new_stock' => (float) $new_stock,
            'new_avg'   => $new_avg,
        ];
    }

    // ── WAC on return — basic cases ───────────────────────────────────────────

    public function test_return_to_zero_stock_uses_cogs_back_as_new_avg(): void
    {
        // Stock was 0 (fully sold), return 5 units at cogs_back=60,000
        // new_avg = (0×0 + 5×60000) / 5 = 60,000
        $result = $this->calcReturnWac(0, 0, 5, 60000);

        $this->assertEqualsWithDelta(5.0, $result['new_stock'], 0.0001);
        $this->assertSame('60000.0000', $result['new_avg']);
    }

    public function test_return_at_same_cogs_keeps_avg_unchanged(): void
    {
        // Stock=10, avg=50,000; return 3 at same cogs=50,000
        // new_avg = (10×50000 + 3×50000) / 13 = 50,000
        $result = $this->calcReturnWac(10, 50000, 3, 50000);

        $this->assertEqualsWithDelta(13.0, $result['new_stock'], 0.0001);
        $this->assertSame('50000.0000', $result['new_avg']);
    }

    public function test_return_pulls_avg_toward_cogs_back(): void
    {
        // Current stock=5 at avg=80,000; returning 5 at cogs_back=60,000
        // new_avg = (5×80000 + 5×60000) / 10 = 70,000
        $result = $this->calcReturnWac(5, 80000, 5, 60000);

        $this->assertEqualsWithDelta(10.0, $result['new_stock'], 0.0001);
        $this->assertSame('70000.0000', $result['new_avg']);
    }

    public function test_return_with_existing_stock_blended(): void
    {
        // Stock=10 avg=50,000; return 2 at cogs_back=100,000 (expensive items back)
        // new_avg = (10×50000 + 2×100000) / 12 = 58,333.3333
        $result = $this->calcReturnWac(10, 50000, 2, 100000);

        $this->assertEqualsWithDelta(12.0, $result['new_stock'], 0.0001);
        $this->assertSame('58333.3333', $result['new_avg']);
    }

    public function test_return_tiny_qty_barely_moves_avg(): void
    {
        // Large stock=1000 avg=50,000; return 1 at cogs=80,000
        // new_avg = (1000×50000 + 1×80000) / 1001 ≈ 50,029.97
        $result = $this->calcReturnWac(1000, 50000, 1, 80000);

        $this->assertEqualsWithDelta(1001.0, $result['new_stock'], 0.0001);
        // Verify it's very close to original avg (barely moved)
        $this->assertEqualsWithDelta(50000, (float) $result['new_avg'], 100.0);
    }

    public function test_return_fractional_qty(): void
    {
        // Fractional units (e.g. 2.5 kg returned of 7.5 kg stock)
        // Stock=7.5 avg=40,000; return 2.5 at cogs=40,000 → avg stays 40,000
        $result = $this->calcReturnWac(7.5, 40000, 2.5, 40000);

        $this->assertEqualsWithDelta(10.0, $result['new_stock'], 0.0001);
        $this->assertSame('40000.0000', $result['new_avg']);
    }

    public function test_return_when_new_stock_is_zero_avg_unchanged(): void
    {
        // Edge case: current_stock=0 + qty_back=0 → new_stock=0 → avg unchanged
        $result = $this->calcReturnWac(0, 55000, 0, 60000);

        $this->assertEqualsWithDelta(0.0, $result['new_stock'], 0.0001);
        $this->assertSame('55000', $result['new_avg']); // unchanged
    }

    // ── Net revenue / profit after returns ───────────────────────────────────

    /**
     * Mirrors recalculate_export_order() net calculation:
     *   net_revenue = orig_revenue - sum_refund
     *   net_cogs    = orig_cogs    - sum_cogs_back
     *   net_profit  = net_revenue  - net_cogs
     */
    private function calcNetFinancials(
        float $orig_revenue,
        float $orig_cogs,
        float $sum_refund,
        float $sum_cogs_back
    ): array {
        $net_revenue = NumberHelper::sub((string) $orig_revenue, (string) $sum_refund);
        $net_cogs    = NumberHelper::sub((string) $orig_cogs,    (string) $sum_cogs_back);
        $net_profit  = NumberHelper::sub($net_revenue, $net_cogs);

        return [
            'net_revenue' => $net_revenue,
            'net_cogs'    => $net_cogs,
            'net_profit'  => $net_profit,
        ];
    }

    public function test_no_return_financials_unchanged(): void
    {
        $fin = $this->calcNetFinancials(1000000, 600000, 0, 0);

        $this->assertSame('1000000.0000', $fin['net_revenue']);
        $this->assertSame('600000.0000',  $fin['net_cogs']);
        $this->assertSame('400000.0000',  $fin['net_profit']);
    }

    public function test_partial_return_reduces_financials(): void
    {
        // Sold 10 × 100,000 = 1,000,000 revenue; COGS 10 × 60,000 = 600,000
        // Return 3 × 100,000 = 300,000 refund; COGS back 3 × 60,000 = 180,000
        $fin = $this->calcNetFinancials(1000000, 600000, 300000, 180000);

        $this->assertSame('700000.0000', $fin['net_revenue']);
        $this->assertSame('420000.0000', $fin['net_cogs']);
        $this->assertSame('280000.0000', $fin['net_profit']);
    }

    public function test_full_return_zeroes_all_financials(): void
    {
        // Full return → net revenue, cogs, profit all zero
        $fin = $this->calcNetFinancials(1000000, 600000, 1000000, 600000);

        $this->assertSame('0.0000',    $fin['net_revenue']);
        $this->assertSame('0.0000',    $fin['net_cogs']);
        $this->assertSame('0.0000',    $fin['net_profit']);
    }

    public function test_profit_preserved_when_margin_zero(): void
    {
        // Sold at cost → profit was 0, after partial return still 0
        $fin = $this->calcNetFinancials(600000, 600000, 300000, 300000);

        $this->assertSame('300000.0000', $fin['net_revenue']);
        $this->assertSame('300000.0000', $fin['net_cogs']);
        $this->assertSame('0.0000',      $fin['net_profit']);
    }

    // ── Export order status determination ────────────────────────────────────

    /**
     * Mirrors the status logic in recalculate_export_order().
     * $items: array of ['sold_qty' => float, 'returned_qty' => float]
     */
    private function determineOrderStatus(array $items, float $total_returned_qty): string
    {
        if ($total_returned_qty <= 0) {
            return 'confirmed';
        }

        $all_fully_returned = ! empty($items) && array_reduce(
            $items,
            fn ($carry, $row) => $carry && ((float) $row['returned_qty'] >= (float) $row['sold_qty']),
            true
        );

        return $all_fully_returned ? 'fully_returned' : 'partial_return';
    }

    public function test_status_confirmed_when_no_returns(): void
    {
        $items = [
            ['sold_qty' => 5, 'returned_qty' => 0],
            ['sold_qty' => 3, 'returned_qty' => 0],
        ];
        $this->assertSame('confirmed', $this->determineOrderStatus($items, 0));
    }

    public function test_status_partial_return_when_some_items_returned(): void
    {
        $items = [
            ['sold_qty' => 5, 'returned_qty' => 2], // 2/5 returned
            ['sold_qty' => 3, 'returned_qty' => 0], // none returned
        ];
        $this->assertSame('partial_return', $this->determineOrderStatus($items, 2));
    }

    public function test_status_partial_return_when_all_items_partially_returned(): void
    {
        $items = [
            ['sold_qty' => 5, 'returned_qty' => 4], // 4/5 — not fully
            ['sold_qty' => 3, 'returned_qty' => 2], // 2/3 — not fully
        ];
        $this->assertSame('partial_return', $this->determineOrderStatus($items, 6));
    }

    public function test_status_fully_returned_when_all_items_fully_returned(): void
    {
        $items = [
            ['sold_qty' => 5, 'returned_qty' => 5],
            ['sold_qty' => 3, 'returned_qty' => 3],
        ];
        $this->assertSame('fully_returned', $this->determineOrderStatus($items, 8));
    }

    public function test_status_partially_returned_if_even_one_item_not_fully_returned(): void
    {
        $items = [
            ['sold_qty' => 5, 'returned_qty' => 5], // fully returned
            ['sold_qty' => 3, 'returned_qty' => 2], // only partially
        ];
        $this->assertSame('partial_return', $this->determineOrderStatus($items, 7));
    }

    public function test_status_fully_returned_with_exact_qty_match(): void
    {
        // Exact match (no floating point ambiguity with whole numbers)
        $items = [['sold_qty' => 1, 'returned_qty' => 1]];
        $this->assertSame('fully_returned', $this->determineOrderStatus($items, 1));
    }

    public function test_status_fully_returned_with_fractional_qty(): void
    {
        // Fractional: sold 2.5 kg, returned 2.5 kg → fully returned
        $items = [['sold_qty' => 2.5, 'returned_qty' => 2.5]];
        $this->assertSame('fully_returned', $this->determineOrderStatus($items, 2.5));
    }

    public function test_status_single_item_partial_return(): void
    {
        $items = [['sold_qty' => 10, 'returned_qty' => 3]];
        $this->assertSame('partial_return', $this->determineOrderStatus($items, 3));
    }
}
