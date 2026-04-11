<?php

namespace HTWarehouse\Pages;

defined('ABSPATH') || exit;

class DashboardPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) wp_die('Unauthorized');
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

        // KPI: This month orders
        $month_kpi = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(total_revenue),0) AS revenue,
                    COALESCE(SUM(total_cogs),0)    AS cogs,
                    COALESCE(SUM(total_profit),0)  AS profit,
                    COUNT(*) AS total_orders
             FROM {$wpdb->prefix}htw_export_orders
             WHERE status = 'confirmed'
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
             WHERE status = 'confirmed'
               AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY month
             ORDER BY month ASC",
            ARRAY_A
        );

        // Top 5 best-selling products this month
        $top5 = $wpdb->get_results($wpdb->prepare(
            "SELECT p.name, SUM(ei.qty) AS qty_sold, SUM(ei.revenue) AS revenue, SUM(ei.profit) AS profit
             FROM {$wpdb->prefix}htw_export_items ei
             JOIN {$wpdb->prefix}htw_export_orders eo ON eo.id = ei.order_id
             JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             WHERE eo.status = 'confirmed'
               AND eo.order_date BETWEEN %s AND %s
             GROUP BY ei.product_id
             ORDER BY qty_sold DESC
             LIMIT 5",
            $month_start,
            $today
        ), ARRAY_A);

        // Low stock alert: products where current_stock <= 5
        $low_stock = $wpdb->get_results(
            "SELECT sku, name, current_stock, unit
             FROM {$wpdb->prefix}htw_products
             WHERE current_stock <= 5
             ORDER BY current_stock ASC
             LIMIT 10",
            ARRAY_A
        );

        wp_send_json_success([
            'kpi'       => [
                'total_products'  => (int)   $stock_kpi->total_products,
                'inventory_value' => (float) $stock_kpi->inventory_value,
                'revenue'         => (float) $month_kpi->revenue,
                'profit'          => (float) $month_kpi->profit,
                'total_orders'    => (int)   $month_kpi->total_orders,
            ],
            'chart'     => $chart,
            'top5'      => $top5,
            'low_stock' => $low_stock,
        ]);
    }
}
