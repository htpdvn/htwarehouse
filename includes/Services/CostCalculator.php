<?php

namespace HTWarehouse\Services;

defined('ABSPATH') || exit;

/**
 * Weighted Average Cost calculator + stock updater.
 * Uses bcmath via NumberHelper when available for precision.
 */
class CostCalculator
{

    /**
     * Recalculate avg_cost after a new import is confirmed.
     *
     * Formula:
     *   new_avg = (current_stock * current_avg + qty * new_unit_cost) / (current_stock + qty)
     *
     * @param int    $product_id
     * @param string $old_stock     Current stock at time of lock (already FOR UPDATE'd by caller).
     * @param string $old_avg       Current avg_cost at time of lock (already FOR UPDATE'd by caller).
     * @param string $qty            Quantity being added.
     * @param string $new_unit_cost Fully allocated cost per unit (after fee distribution).
     */
    public static function add_stock(int $product_id, string $old_stock, string $old_avg, string $qty, string $new_unit_cost): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htw_products';

        $total_qty = NumberHelper::add($old_stock, $qty);
        $old_total = NumberHelper::mul($old_stock, $old_avg);
        $new_total = NumberHelper::mul($qty, $new_unit_cost);
        $sum       = NumberHelper::add($old_total, $new_total);

        $new_avg = NumberHelper::comp($total_qty, '0', 4) > 0
            ? NumberHelper::div($sum, $total_qty, 4)
            : $new_unit_cost;

        $wpdb->update(
            $table,
            [
                'current_stock' => $total_qty,
                'avg_cost'      => $new_avg,
            ],
            ['id' => $product_id],
            ['%f', '%f'],
            ['%d']
        );
    }

    /**
     * Deduct stock when a sale order is confirmed.
     *
     * @param int    $product_id
     * @param float  $locked_stock   Current stock at time of FOR UPDATE lock (read by caller).
     * @param float  $locked_avg     avg_cost at time of FOR UPDATE lock (read by caller).
     * @param float  $qty            Quantity to deduct.
     * @return float The avg_cost per unit at the time of deduction.
     */
    public static function deduct_stock(int $product_id, float $locked_stock, float $locked_avg, float $qty): float
    {
        global $wpdb;
        $table     = $wpdb->prefix . 'htw_products';
        $new_stock = max(0, $locked_stock - $qty);

        $wpdb->update(
            $table,
            ['current_stock' => $new_stock],
            ['id' => $product_id],
            ['%f'],
            ['%d']
        );

        return $locked_avg;
    }

    /**
     * Distribute batch extra costs (shipping, tax, other) across items
     * proportionally by item value (qty * unit_price).
     * Uses bcmath via NumberHelper for precision on all financial calculations.
     *
     * @param array $items  Each: [ product_id, qty, unit_price ]
     * @param float $extra_cost  Total extra cost to distribute.
     * @return array  Same items but adds allocated_cost_per_unit and total_cost.
     */
    public static function allocate_extra_costs(array $items, float $extra_cost): array
    {
        // Compute total value using NumberHelper for precision
        $total_value = '0';
        foreach ($items as $item) {
            $item_value = NumberHelper::mul((string) $item['qty'], (string) $item['unit_price']);
            $total_value = NumberHelper::add($total_value, $item_value);
        }

        $extra_str = (string) $extra_cost;

        foreach ($items as &$item) {
            $qty        = (string) $item['qty'];
            $unit_price = (string) $item['unit_price'];
            $item_value = NumberHelper::mul($qty, $unit_price);
            $share      = NumberHelper::comp($total_value, '0', 4) > 0
                ? NumberHelper::div($item_value, $total_value, 6)
                : '0';
            $allocated  = NumberHelper::mul($extra_str, $share);

            $item['allocated_cost_per_unit'] = NumberHelper::comp($qty, '0', 4) > 0
                ? NumberHelper::add($unit_price, NumberHelper::div($allocated, $qty, 4))
                : $unit_price;

            $item['total_cost'] = NumberHelper::mul($qty, (string) $item['allocated_cost_per_unit']);
        }

        return $items;
    }
}
