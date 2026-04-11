<?php

namespace HTWarehouse\Services;

defined('ABSPATH') || exit;

/**
 * Weighted Average Cost calculator + stock updater.
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
     * @param float  $qty           Quantity being added.
     * @param float  $new_unit_cost Fully allocated cost per unit (after fee distribution).
     */
    public static function add_stock(int $product_id, float $qty, float $new_unit_cost): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htw_products';

        $product = $wpdb->get_row(
            $wpdb->prepare("SELECT current_stock, avg_cost FROM {$table} WHERE id = %d", $product_id)
        );

        if (! $product) {
            return;
        }

        $old_stock = (float) $product->current_stock;
        $old_avg   = (float) $product->avg_cost;

        $total_qty  = $old_stock + $qty;
        $new_avg    = $total_qty > 0
            ? (($old_stock * $old_avg) + ($qty * $new_unit_cost)) / $total_qty
            : $new_unit_cost;

        $wpdb->update(
            $table,
            [
                'current_stock' => $total_qty,
                'avg_cost'      => round($new_avg, 4),
            ],
            ['id' => $product_id],
            ['%f', '%f'],
            ['%d']
        );
    }

    /**
     * Deduct stock when a sale order is confirmed.
     *
     * @param int   $product_id
     * @param float $qty
     * @return float The avg_cost per unit at the time of deduction.
     */
    public static function deduct_stock(int $product_id, float $qty): float
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'htw_products';
        $product = $wpdb->get_row(
            $wpdb->prepare("SELECT current_stock, avg_cost FROM {$table} WHERE id = %d", $product_id)
        );

        if (! $product) {
            return 0.0;
        }

        $cogs_per_unit = (float) $product->avg_cost;
        $new_stock     = max(0, (float) $product->current_stock - $qty);

        $wpdb->update(
            $table,
            ['current_stock' => $new_stock],
            ['id' => $product_id],
            ['%f'],
            ['%d']
        );

        return $cogs_per_unit;
    }

    /**
     * Distribute batch extra costs (shipping, tax, other) across items
     * proportionally by item value (qty * unit_price).
     *
     * @param array $items  Each: [ product_id, qty, unit_price ]
     * @param float $extra_cost  Total extra cost to distribute.
     * @return array  Same items but adds allocated_cost_per_unit and total_cost.
     */
    public static function allocate_extra_costs(array $items, float $extra_cost): array
    {
        $total_value = array_sum(
            array_map(fn($i) => $i['qty'] * $i['unit_price'], $items)
        );

        foreach ($items as &$item) {
            $item_value  = $item['qty'] * $item['unit_price'];
            $share       = $total_value > 0 ? $item_value / $total_value : 0;
            $allocated   = $extra_cost * $share;

            $item['allocated_cost_per_unit'] = $item['qty'] > 0
                ? ($item['unit_price'] + ($allocated / $item['qty']))
                : $item['unit_price'];

            $item['total_cost'] = $item['qty'] * $item['allocated_cost_per_unit'];
        }

        return $items;
    }
}
