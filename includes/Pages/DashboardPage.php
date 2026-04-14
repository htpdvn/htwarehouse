<?php

namespace HTWarehouse\Pages;

defined('ABSPATH') || exit;

class DashboardPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) wp_die('Unauthorized');

        global $wpdb;
        $low_stock = $wpdb->get_results(
            "SELECT sku, name, current_stock, unit
             FROM {$wpdb->prefix}htw_products
             WHERE current_stock <= 5
             ORDER BY current_stock ASC
             LIMIT 10",
            ARRAY_A
        );

        include HTW_PLUGIN_DIR . 'templates/dashboard.php';
    }

    public static function ajax_data(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $month_start = date('Y-m-01');
        $today       = date('Y-m-d');

        // KPI: Total products & stock value
        $stock_kpi = $wpdb->get_row(
            "SELECT COUNT(*) AS total_products,
                    COALESCE(SUM(current_stock * avg_cost), 0) AS inventory_value
             FROM {$wpdb->prefix}htw_products"
        );

        // KPI: This month orders — exclude fully_returned since those have zero net revenue
        // and inflating total_orders count misleads the user about actual sales activity.
        $month_kpi = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(total_revenue),0)                                AS revenue,
                    COALESCE(SUM(total_cogs),0)                                   AS cogs,
                    COALESCE(SUM(total_profit),0)                                 AS profit,
                    COUNT(CASE WHEN status IN ('confirmed','partial_return') THEN 1 END) AS total_orders
             FROM {$wpdb->prefix}htw_export_orders
             WHERE status IN ('confirmed', 'partial_return', 'fully_returned')
               AND order_date BETWEEN %s AND %s",
            $month_start,
            $today
        ));

        // Chart: Last 6 months revenue + profit
        $chart = $wpdb->get_results(
            "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month,
                    SUM(total_revenue) AS revenue,
                    SUM(total_profit)  AS profit
             FROM {$wpdb->prefix}htw_export_orders
             WHERE status IN ('confirmed', 'partial_return', 'fully_returned')
               AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(order_date, '%Y-%m')
             ORDER BY MIN(order_date) ASC",
            ARRAY_A
        );

        // Top 5 best-selling products this month — NET of confirmed returns.
        // Without this, returned items inflate qty_sold and revenue figures,
        // creating inconsistency with the profit-by-product report.
        $top5 = $wpdb->get_results($wpdb->prepare(
            "SELECT p.name,
                    SUM(ei.qty)     - COALESCE(SUM(ret.qty_ret),    0) AS qty_sold,
                    SUM(ei.revenue) - COALESCE(SUM(ret.refund_amt),  0) AS revenue,
                    SUM(ei.profit)  - COALESCE(SUM(ret.profit_back), 0) AS profit
             FROM {$wpdb->prefix}htw_export_items ei
             JOIN {$wpdb->prefix}htw_export_orders eo ON eo.id = ei.order_id
             JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             LEFT JOIN (
                 SELECT ri.export_item_id,
                        SUM(ri.qty_returned)                                      AS qty_ret,
                        SUM(ri.qty_returned * ri.sale_price)                      AS refund_amt,
                        SUM(ri.qty_returned * (ri.sale_price - ri.cogs_per_unit)) AS profit_back
                 FROM {$wpdb->prefix}htw_return_items ri
                 INNER JOIN {$wpdb->prefix}htw_return_orders ro
                         ON ro.id = ri.return_order_id AND ro.status = 'confirmed'
                 GROUP BY ri.export_item_id
             ) ret ON ret.export_item_id = ei.id
             WHERE eo.status IN ('confirmed', 'partial_return', 'fully_returned')
               AND eo.order_date BETWEEN %s AND %s
             GROUP BY ei.product_id
             ORDER BY qty_sold DESC
             LIMIT 5",
            $month_start,
            $today
        ), ARRAY_A);

        // KPI: Purchase orders
        $po_kpi = $wpdb->get_row(
            "SELECT COUNT(*) AS active_pos,
                    COALESCE(SUM(amount_remaining), 0) AS total_debt,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_pos
             FROM {$wpdb->prefix}htw_purchase_orders
             WHERE status IN ('draft', 'confirmed', 'received')"
        );

        wp_send_json_success([
            'kpi'   => [
                'total_products'  => (int)   $stock_kpi->total_products,
                'inventory_value' => (float) $stock_kpi->inventory_value,
                'revenue'         => (float) $month_kpi->revenue,
                'profit'          => (float) $month_kpi->profit,
                'total_orders'    => (int)   $month_kpi->total_orders,
                'po_active'       => (int)   $po_kpi->active_pos,
                'po_debt'         => (float) $po_kpi->total_debt,
            ],
            'chart' => $chart,
            'top5'  => $top5,
        ]);
    }
}
