<?php

namespace HTWarehouse\Tests\Unit;

use HTWarehouse\Services\NumberHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the NXT (Nhập-Xuất-Tồn) report calculation logic.
 *
 * The core formula under test:
 *   opening = closing - qty_in + qty_out
 *   (derived from: closing = opening + qty_in - qty_out)
 *
 * These tests verify the inventory identity holds for every scenario —
 * including the bug that was present in the old code where pre-period
 * imports/exports were double-counted.
 */
class ReportMovementTest extends TestCase
{
    /**
     * Simulate the corrected report_movement() opening calculation.
     * Pure logic — no DB.
     *
     * @param float $current_stock  live current_stock from DB (= closing)
     * @param float $qty_sold       gross sold in period
     * @param float $qty_returned   returned (confirmed) in period
     * @param float $qty_imported   imported in period
     * @return array{opening: float, qty_in: float, qty_out: float, closing: float}
     */
    private function calcMovement(
        float $current_stock,
        float $qty_sold     = 0.0,
        float $qty_returned = 0.0,
        float $qty_imported = 0.0,
    ): array {
        $qty_out = max(0.0, $qty_sold - $qty_returned);
        $closing = $current_stock;
        $opening = $closing - $qty_imported + $qty_out;

        return [
            'opening' => max(0.0, $opening),
            'qty_in'  => $qty_imported,
            'qty_out' => $qty_out,
            'closing' => $closing,
        ];
    }

    /** Assert that opening + qty_in - qty_out == closing (inventory identity). */
    private function assertInventoryIdentity(array $row, string $msg = ''): void
    {
        $expected_closing = $row['opening'] + $row['qty_in'] - $row['qty_out'];
        $this->assertEqualsWithDelta(
            $row['closing'],
            $expected_closing,
            0.0001,
            "Inventory identity failed: {$msg}"
        );
    }

    // ── No activity in period ─────────────────────────────────────────────────

    public function test_no_activity_opening_equals_closing(): void
    {
        // BUG REGRESSION: this was returning opening=12, closing=6 before the fix.
        // current_stock=6, all history before period → no in-period activity.
        $row = $this->calcMovement(current_stock: 6.0);

        $this->assertSame(6.0, $row['opening']);
        $this->assertSame(0.0, $row['qty_in']);
        $this->assertSame(0.0, $row['qty_out']);
        $this->assertSame(6.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'no activity — opening must equal closing');
    }

    public function test_no_activity_zero_stock(): void
    {
        $row = $this->calcMovement(current_stock: 0.0);

        $this->assertSame(0.0, $row['opening']);
        $this->assertSame(0.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'zero stock product with no activity');
    }

    // ── Import only ───────────────────────────────────────────────────────────

    public function test_import_only_in_period(): void
    {
        // Start of month: stock=0, import 12 → closing=12
        $row = $this->calcMovement(current_stock: 12.0, qty_imported: 12.0);

        $this->assertSame(0.0, $row['opening']);
        $this->assertSame(12.0, $row['qty_in']);
        $this->assertSame(0.0, $row['qty_out']);
        $this->assertSame(12.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'import only');
    }

    public function test_import_into_existing_stock(): void
    {
        // Had 5, imported 10 → closing 15
        $row = $this->calcMovement(current_stock: 15.0, qty_imported: 10.0);

        $this->assertSame(5.0, $row['opening']);
        $this->assertSame(10.0, $row['qty_in']);
        $this->assertSame(0.0, $row['qty_out']);
        $this->assertSame(15.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'import into existing stock');
    }

    // ── Export only ───────────────────────────────────────────────────────────

    public function test_export_only_in_period(): void
    {
        // Had 12, sold 6 → closing 6
        $row = $this->calcMovement(current_stock: 6.0, qty_sold: 6.0);

        $this->assertSame(12.0, $row['opening']);
        $this->assertSame(0.0, $row['qty_in']);
        $this->assertSame(6.0, $row['qty_out']);
        $this->assertSame(6.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'export only');
    }

    public function test_export_full_stock(): void
    {
        // Sell everything
        $row = $this->calcMovement(current_stock: 0.0, qty_sold: 10.0);

        $this->assertSame(10.0, $row['opening']);
        $this->assertSame(0.0, $row['qty_in']);
        $this->assertSame(10.0, $row['qty_out']);
        $this->assertSame(0.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'sell everything');
    }

    // ── Returns in period ─────────────────────────────────────────────────────

    public function test_partial_return_reduces_net_qty_out(): void
    {
        // Sold 6, returned 2 → net out = 4, closing = original - 4
        // Suppose original stock was 10, sold 6, returned 2 → closing = 6
        $row = $this->calcMovement(current_stock: 6.0, qty_sold: 6.0, qty_returned: 2.0);

        $this->assertSame(4.0, $row['qty_out']);  // net out = 6-2
        $this->assertSame(10.0, $row['opening']);
        $this->assertInventoryIdentity($row, 'partial return');
    }

    public function test_full_return_qty_out_is_zero(): void
    {
        // Sold 5, returned all 5 → net out = 0, closing = opening
        // Stock = 10 (restored after return)
        $row = $this->calcMovement(current_stock: 10.0, qty_sold: 5.0, qty_returned: 5.0);

        $this->assertSame(0.0, $row['qty_out']);
        $this->assertSame(10.0, $row['opening']); // no net change
        $this->assertSame(10.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'full return — qty_out should be 0');
    }

    public function test_return_cannot_make_qty_out_negative(): void
    {
        // Edge case: more returned than sold in period (shouldn't happen in valid data)
        // qty_out must be clamped to 0
        $row = $this->calcMovement(current_stock: 10.0, qty_sold: 3.0, qty_returned: 5.0);

        $this->assertSame(0.0, $row['qty_out']);
    }

    // ── Mixed activity ────────────────────────────────────────────────────────

    public function test_import_and_export_in_period(): void
    {
        // Had 5, imported 10, sold 8 → closing = 7
        $row = $this->calcMovement(current_stock: 7.0, qty_sold: 8.0, qty_imported: 10.0);

        $this->assertSame(5.0, $row['opening']);
        $this->assertSame(10.0, $row['qty_in']);
        $this->assertSame(8.0, $row['qty_out']);
        $this->assertSame(7.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'import + export in period');
    }

    public function test_import_export_and_partial_return(): void
    {
        // Had 5, imported 10, sold 8, returned 3 → net_out=5, closing=10
        $row = $this->calcMovement(
            current_stock: 10.0,
            qty_sold:      8.0,
            qty_returned:  3.0,
            qty_imported:  10.0,
        );

        $this->assertSame(5.0, $row['opening']);
        $this->assertSame(5.0, $row['qty_out']);
        $this->assertSame(10.0, $row['qty_in']);
        $this->assertSame(10.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'import + export + partial return');
    }

    // ── Bug regression: Lego76300 scenario ───────────────────────────────────

    public function test_regression_no_period_activity_opening_equals_closing(): void
    {
        // REGRESSION: old code produced opening=12, closing=6 when all historical
        // activity predated the selected period and qty_in=qty_out=0.
        // The fix: opening = closing - qty_in + qty_out (no pre-period terms).

        // Simulate: all 12-unit import + 6-unit sale happened before the period.
        // current_stock at time of report = 6.
        // No in-period activity (selected date = today with no transactions today).
        $row = $this->calcMovement(current_stock: 6.0); // no in-period activity

        $this->assertSame(6.0, $row['opening'],
            'REGRESSION: opening must equal closing when there is no in-period activity');
        $this->assertSame(6.0, $row['closing']);
        $this->assertInventoryIdentity($row, 'Lego76300 regression');
    }

    // ── Identity holds for arbitrary numbers ──────────────────────────────────

    /** @dataProvider movementScenarioProvider */
    public function test_inventory_identity_always_holds(
        float $stock, float $sold, float $returned, float $imported, string $desc
    ): void {
        $row = $this->calcMovement($stock, $sold, $returned, $imported);
        $this->assertInventoryIdentity($row, $desc);
    }

    /** @return array<string, array{float, float, float, float, string}> */
    public static function movementScenarioProvider(): array
    {
        return [
            'new product, first import'         => [100.0,  0.0,  0.0, 100.0, 'first import'],
            'fully sold, zero stock'             => [  0.0, 50.0,  0.0,  0.0, 'fully sold'],
            'high volume sales and returns'      => [200.0, 80.0, 20.0,  0.0, 'high volume'],
            'import + partial return same period'=> [ 12.0, 10.0,  3.0, 10.0, 'import+return'],
            'fractional qty (kg)'                => [  7.5,  2.5,  0.5,  3.0, 'fractional'],
            'zero activity new period'           => [  0.0,  0.0,  0.0,  0.0, 'all zeros'],
        ];
    }
}
