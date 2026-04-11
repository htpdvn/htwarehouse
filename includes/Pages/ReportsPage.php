<?php

namespace HTWarehouse\Pages;

defined('ABSPATH') || exit;

class ReportsPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) wp_die('Unauthorized');
        include HTW_PLUGIN_DIR . 'templates/reports/index.php';
    }

    public static function ajax_data(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        $report   = sanitize_text_field($_POST['report'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');

        switch ($report) {
            case 'stock':
                wp_send_json_success(self::report_current_stock());
                break;
            case 'movement':
                wp_send_json_success(self::report_movement($date_from, $date_to));
                break;
            case 'profit_by_product':
                wp_send_json_success(self::report_profit_product($date_from, $date_to));
                break;
            case 'profit_by_channel':
                wp_send_json_success(self::report_profit_channel($date_from, $date_to));
                break;
            default:
                wp_send_json_error('Unknown report type');
        }
    }

    // ── Tồn kho hiện tại ─────────────────────────────────────────────────────
    private static function report_current_stock(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, sku, name, category, unit, current_stock, avg_cost,
                    (current_stock * avg_cost) AS inventory_value
             FROM {$wpdb->prefix}htw_products
             ORDER BY category, name",
            ARRAY_A
        );
        $total_value = array_sum(array_column($rows, 'inventory_value'));
        return ['rows' => $rows, 'total_inventory_value' => $total_value];
    }

    // ── Nhập Xuất Tồn theo kỳ ────────────────────────────────────────────────
    private static function report_movement(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        // Opening stock = current_stock + qty_sold_in_period - qty_imported_in_period
        $products = $wpdb->get_results(
            "SELECT id, sku, name, unit, current_stock, avg_cost FROM {$wpdb->prefix}htw_products ORDER BY name",
            ARRAY_A
        );

        $rows = [];
        foreach ($products as $p) {
            $pid = (int) $p['id'];

            $qty_in = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(ii.qty),0)
                 FROM {$wpdb->prefix}htw_import_items ii
                 JOIN {$wpdb->prefix}htw_import_batches ib ON ib.id = ii.batch_id
                 WHERE ii.product_id = %d
                   AND ib.status = 'confirmed'
                   AND ib.import_date BETWEEN %s AND %s",
                $pid,
                $from,
                $to
            ));

            $qty_out = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(ei.qty),0)
                 FROM {$wpdb->prefix}htw_export_items ei
                 JOIN {$wpdb->prefix}htw_export_orders eo ON eo.id = ei.order_id
                 WHERE ei.product_id = %d
                   AND eo.status = 'confirmed'
                   AND eo.order_date BETWEEN %s AND %s",
                $pid,
                $from,
                $to
            ));

            $closing = (float) $p['current_stock'];
            // Inventory identity: Opening + qty_in - qty_out = Closing
            // So: Opening = Closing - qty_in + qty_out
            $opening = $closing - $qty_in + $qty_out;

            $rows[] = [
                'sku'           => $p['sku'],
                'name'          => $p['name'],
                'unit'          => $p['unit'],
                'opening_stock' => max(0, $opening),
                'qty_in'        => $qty_in,
                'qty_out'       => $qty_out,
                'closing_stock' => $closing,
                'avg_cost'      => $p['avg_cost'],
            ];
        }

        return ['rows' => $rows, 'date_from' => $from, 'date_to' => $to];
    }

    // ── Lợi nhuận theo sản phẩm ──────────────────────────────────────────────
    private static function report_profit_product(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.sku, p.name, p.unit,
                    SUM(ei.qty)     AS total_qty,
                    SUM(ei.revenue) AS total_revenue,
                    SUM(ei.cogs)    AS total_cogs,
                    SUM(ei.profit)  AS total_profit,
                    CASE WHEN SUM(ei.revenue) > 0
                         THEN ROUND(SUM(ei.profit) / SUM(ei.revenue) * 100, 2)
                         ELSE 0 END AS margin_pct
             FROM {$wpdb->prefix}htw_export_items ei
             JOIN {$wpdb->prefix}htw_export_orders eo ON eo.id = ei.order_id
             JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             WHERE eo.status = 'confirmed'
               AND eo.order_date BETWEEN %s AND %s
             GROUP BY ei.product_id
             ORDER BY total_profit DESC",
            $from,
            $to
        ), ARRAY_A);

        return ['rows' => $rows, 'date_from' => $from, 'date_to' => $to];
    }

    // ── Lợi nhuận theo kênh bán ───────────────────────────────────────────────
    private static function report_profit_channel(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT channel,
                    COUNT(*) AS total_orders,
                    SUM(total_revenue) AS revenue,
                    SUM(total_cogs)    AS cogs,
                    SUM(total_profit)  AS profit,
                    CASE WHEN SUM(total_revenue) > 0
                         THEN ROUND(SUM(total_profit) / SUM(total_revenue) * 100, 2)
                         ELSE 0 END AS margin_pct
             FROM {$wpdb->prefix}htw_export_orders
             WHERE status = 'confirmed'
               AND order_date BETWEEN %s AND %s
             GROUP BY channel
             ORDER BY profit DESC",
            $from,
            $to
        ), ARRAY_A);

        return ['rows' => $rows, 'date_from' => $from, 'date_to' => $to];
    }
}
