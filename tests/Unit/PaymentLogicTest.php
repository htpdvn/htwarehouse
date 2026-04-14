<?php

namespace HTWarehouse\Tests\Unit;

use HTWarehouse\Services\NumberHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Purchase Order payment business logic.
 *
 * Tests three distinct areas extracted from PurchaseOrderPage:
 *
 * 1. OVERPAYMENT GUARD (ajax_record_payment)
 *    Reject new payment if: amount > amount_remaining + 1đ tolerance
 *
 * 2. EDIT PAYMENT GUARD (ajax_edit_payment)
 *    Reject edited payment if: projected_remaining < -1đ
 *    projected_remaining = old_remaining + old_payment_amount - new_amount
 *
 * 3. STATUS DETERMINATION
 *    After add/edit/delete payment, recalculate:
 *    - new_remaining = max(0, total_amount - new_paid)
 *    - new_status    = 'paid_off' if remaining ≤ 0, else current status
 *
 * 4. STATUS REGRESSION (ajax_delete_payment)
 *    When deleting a payment causes remaining > 0 and current status = 'paid_off':
 *    - has confirmed import batch → 'received'
 *    - no confirmed batch        → 'confirmed'
 */
class PaymentLogicTest extends TestCase
{
    // ── Helpers (mirror Page logic as pure functions) ─────────────────────────

    /**
     * Returns true if the new payment would cause overpayment.
     * Mirrors: if ($amount > $amount_remaining + 1)
     */
    private function isOverpayment(float $amount, float $amount_remaining): bool
    {
        return $amount > $amount_remaining + 1;
    }

    /**
     * Returns true if editing a payment to $new_amount would cause overpayment.
     * projected_remaining = old_remaining + old_payment_amount - new_amount
     * Mirrors: if ($projected_remaining < -1)
     */
    private function isEditOverpayment(
        float $old_remaining,
        float $old_payment_amount,
        float $new_amount
    ): bool {
        $projected_remaining = $old_remaining + $old_payment_amount - $new_amount;
        return $projected_remaining < -1;
    }

    /**
     * Compute new PO financial state after adding/changing payments.
     * Mirrors the recalculation logic in ajax_record_payment() and ajax_edit_payment().
     */
    private function calcAfterPayment(
        float $total_amount,
        float $new_total_paid,
        string $current_status
    ): array {
        $new_remaining = max(0.0, $total_amount - $new_total_paid);
        $new_status    = NumberHelper::isZeroOrNegative(
            number_format($new_remaining, 2, '.', '')
        ) ? 'paid_off' : $current_status;

        return [
            'amount_paid'      => $new_total_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ];
    }

    /**
     * Determine status after a payment is deleted.
     * Mirrors: ajax_delete_payment() regression logic.
     */
    private function calcStatusAfterDelete(
        string $current_status,
        float  $new_remaining,
        bool   $has_confirmed_batch
    ): string {
        if ($current_status === 'paid_off' && $new_remaining > 0.01) {
            return $has_confirmed_batch ? 'received' : 'confirmed';
        }
        return $current_status;
    }

    // ── Overpayment guard (new payment) ──────────────────────────────────────

    public function test_payment_within_remaining_allowed(): void
    {
        // Remaining = 500,000đ, pay 500,000đ → exact → allowed
        $this->assertFalse($this->isOverpayment(500000, 500000));
    }

    public function test_payment_slightly_under_remaining_allowed(): void
    {
        $this->assertFalse($this->isOverpayment(499999, 500000));
    }

    public function test_payment_with_1d_tolerance_allowed(): void
    {
        // Allowed to overpay by up to 1đ (rounding tolerance)
        $this->assertFalse($this->isOverpayment(500001, 500000));
    }

    public function test_payment_exceeds_remaining_by_2d_rejected(): void
    {
        // 2đ over → rejected
        $this->assertTrue($this->isOverpayment(500002, 500000));
    }

    public function test_payment_far_exceeds_remaining_rejected(): void
    {
        $this->assertTrue($this->isOverpayment(1000000, 500000));
    }

    public function test_payment_when_fully_paid_rejected(): void
    {
        // Remaining = 0, tried to pay more → rejected
        $this->assertTrue($this->isOverpayment(1000, 0));
    }

    public function test_payment_zero_amount_always_allowed_by_guard(): void
    {
        // Guard only checks amount > remaining + 1; 0 always passes.
        // (The amount > 0 check is done separately before the guard.)
        $this->assertFalse($this->isOverpayment(0, 0));
    }

    // ── Overpayment guard (edit payment) ─────────────────────────────────────

    public function test_edit_payment_downward_always_allowed(): void
    {
        // old_remaining=0 (paid_off), old_payment=300,000 → new=200,000
        // projected = 0 + 300000 - 200000 = 100,000 > -1 → allowed
        $this->assertFalse($this->isEditOverpayment(0, 300000, 200000));
    }

    public function test_edit_payment_same_amount_allowed(): void
    {
        $this->assertFalse($this->isEditOverpayment(0, 300000, 300000));
    }

    public function test_edit_payment_increase_within_remaining_allowed(): void
    {
        // old_remaining=100,000; increase payment by 50,000
        // projected = 100000 + 300000 - 350000 = 50,000 > -1 → allowed
        $this->assertFalse($this->isEditOverpayment(100000, 300000, 350000));
    }

    public function test_edit_payment_increase_exactly_clears_remaining_allowed(): void
    {
        // old_remaining=50,000; increase payment by exactly 50,000
        // projected = 50000 + 300000 - 350000 = 0 → allowed (border case)
        $this->assertFalse($this->isEditOverpayment(50000, 300000, 350000));
    }

    public function test_edit_payment_increase_with_1d_tolerance_allowed(): void
    {
        // projected = -1 → exactly at tolerance boundary → allowed
        $this->assertFalse($this->isEditOverpayment(50000, 300000, 350001));
    }

    public function test_edit_payment_exceeds_by_2d_rejected(): void
    {
        // projected = 50000 + 300000 - 350002 = -2 < -1 → rejected
        $this->assertTrue($this->isEditOverpayment(50000, 300000, 350002));
    }

    public function test_edit_payment_far_exceeds_remaining_rejected(): void
    {
        $this->assertTrue($this->isEditOverpayment(0, 100000, 999999));
    }

    // ── Status after adding payment ───────────────────────────────────────────

    public function test_payment_clears_debt_becomes_paid_off(): void
    {
        // total=1,000,000; paid=1,000,000 → paid_off
        $result = $this->calcAfterPayment(1000000, 1000000, 'confirmed');

        $this->assertSame(0.0,        $result['amount_remaining']);
        $this->assertSame('paid_off', $result['status']);
    }

    public function test_partial_payment_keeps_current_status(): void
    {
        // total=1,000,000; paid=800,000 → remaining=200,000 → stays 'confirmed'
        $result = $this->calcAfterPayment(1000000, 800000, 'confirmed');

        $this->assertSame(200000.0,    $result['amount_remaining']);
        $this->assertSame('confirmed', $result['status']);
    }

    public function test_partial_payment_on_received_order_keeps_received(): void
    {
        $result = $this->calcAfterPayment(1000000, 800000, 'received');

        $this->assertSame(200000.0,   $result['amount_remaining']);
        $this->assertSame('received', $result['status']);
    }

    public function test_overpayment_clamped_to_zero_remaining(): void
    {
        // Should not result in negative remaining (clamped by max(0, ...))
        $result = $this->calcAfterPayment(1000000, 1000001, 'confirmed');

        $this->assertSame(0.0,        $result['amount_remaining']);
        $this->assertSame('paid_off', $result['status']);
    }

    public function test_zero_payment_total_keeps_full_debt(): void
    {
        // No payments at all
        $result = $this->calcAfterPayment(500000, 0, 'received');

        $this->assertSame(500000.0,   $result['amount_remaining']);
        $this->assertSame('received', $result['status']);
    }

    public function test_tiny_remaining_rounds_to_paid_off(): void
    {
        // remaining = 0.004 → number_format gives "0.00" → isZeroOrNegative → paid_off
        $result = $this->calcAfterPayment(1000000, 999999.996, 'received');

        $this->assertSame('paid_off', $result['status']);
    }

    // ── Status regression after deleting a payment ───────────────────────────

    public function test_delete_payment_stays_received_if_batch_confirmed(): void
    {
        // Was paid_off, delete payment → remaining > 0, has confirmed batch → 'received'
        $status = $this->calcStatusAfterDelete('paid_off', 200000, true);
        $this->assertSame('received', $status);
    }

    public function test_delete_payment_reverts_to_confirmed_if_no_confirmed_batch(): void
    {
        // Was paid_off, delete payment → remaining > 0, no confirmed batch → 'confirmed'
        $status = $this->calcStatusAfterDelete('paid_off', 200000, false);
        $this->assertSame('confirmed', $status);
    }

    public function test_delete_payment_stays_paid_off_if_still_fully_paid(): void
    {
        // Delete a payment but remaining is still ≤ 0.01 → stays paid_off
        $status = $this->calcStatusAfterDelete('paid_off', 0.005, true);
        $this->assertSame('paid_off', $status);
    }

    public function test_delete_payment_non_paid_off_status_unchanged(): void
    {
        // PO is 'received' (not paid_off) → status unchanged regardless of remaining
        $status = $this->calcStatusAfterDelete('received', 500000, false);
        $this->assertSame('received', $status);
    }

    public function test_delete_payment_confirmed_status_unchanged(): void
    {
        $status = $this->calcStatusAfterDelete('confirmed', 500000, false);
        $this->assertSame('confirmed', $status);
    }

    public function test_delete_payment_border_remaining_001_stays_paid_off(): void
    {
        // Exactly 0.01 remaining → still within "paid" threshold → paid_off
        $status = $this->calcStatusAfterDelete('paid_off', 0.01, true);
        $this->assertSame('paid_off', $status);
    }

    public function test_delete_payment_border_remaining_just_above_001_reverts(): void
    {
        // 0.011 → just above threshold → reverts
        $status = $this->calcStatusAfterDelete('paid_off', 0.011, true);
        $this->assertSame('received', $status);
    }

    // ── Combined scenarios ────────────────────────────────────────────────────

    /** @dataProvider paymentStatusDataProvider */
    public function test_payment_status_determination(
        float  $total,
        float  $paid,
        string $current,
        string $expected_status,
        float  $expected_remaining,
        string $desc
    ): void {
        $result = $this->calcAfterPayment($total, $paid, $current);
        $this->assertSame($expected_status,    $result['status'],           $desc . ' — status');
        $this->assertEqualsWithDelta($expected_remaining, $result['amount_remaining'], 0.001, $desc . ' — remaining');
    }

    /** @return array<string, array{float, float, string, string, float, string}> */
    public static function paymentStatusDataProvider(): array
    {
        return [
            'first partial payment'    => [1000000, 300000,  'confirmed', 'confirmed', 700000, 'first partial payment'],
            'second partial payment'   => [1000000, 700000,  'confirmed', 'confirmed', 300000, 'second partial payment'],
            'final exact payment'      => [1000000, 1000000, 'confirmed', 'paid_off',       0, 'final exact payment'],
            'payment on received PO'   => [2000000, 500000,  'received',  'received',  1500000, 'received PO partial pay'],
            'full payment on received' => [2000000, 2000000, 'received',  'paid_off',       0, 'received PO full pay'],
            'rounding: 0.5đ gap stays confirmed' => [1000000, 999999.5, 'confirmed', 'confirmed', 0.5, '0.5đ remainder stays confirmed (not paid_off)'],
        ];
    }
}
