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
                    COALESCE(current_stock, 0) * COALESCE(avg_cost, 0) AS inventory_value
             FROM {$wpdb->prefix}htw_products
             ORDER BY category, name",
            ARRAY_A
        );
        $total_value = 0;
        foreach ($rows as $row) {
            $total_value += (float) $row['inventory_value'];
        }
        return ['rows' => $rows, 'total_inventory_value' => $total_value];
    }

    // ── Nhập Xuất Tồn theo kỳ ────────────────────────────────────────────────
    private static function report_movement(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        // Load all confirmed batches/orders in a single query per type to minimise DB round-trips
        $confirmed_imports = $wpdb->get_results(
            "SELECT ib.import_date, ii.product_id, ii.qty
             FROM {$wpdb->prefix}htw_import_batches ib
             JOIN {$wpdb->prefix}htw_import_items ii ON ii.batch_id = ib.id
             WHERE ib.status = 'confirmed'",
            ARRAY_A
        );

        $confirmed_exports = $wpdb->get_results(
            "SELECT eo.order_date, ei.product_id, ei.qty
             FROM {$wpdb->prefix}htw_export_orders eo
             JOIN {$wpdb->prefix}htw_export_items ei ON ei.order_id = eo.id
             WHERE eo.status = 'confirmed'",
            ARRAY_A
        );

        $products = $wpdb->get_results(
            "SELECT id, sku, name, unit, current_stock, avg_cost
             FROM {$wpdb->prefix}htw_products ORDER BY name",
            ARRAY_A
        );

        // Index imports/exports by product_id for O(1) lookup
        $imports_before = [];
        $imports_in_period = [];
        $exports_before = [];
        $exports_in_period = [];

        foreach ($confirmed_imports as $row) {
            $pid = (int) $row['product_id'];
            if ($row['import_date'] < $from) {
                $imports_before[$pid] = ($imports_before[$pid] ?? 0) + (float) $row['qty'];
            } else {
                $imports_in_period[$pid] = ($imports_in_period[$pid] ?? 0) + (float) $row['qty'];
            }
        }

        foreach ($confirmed_exports as $row) {
            $pid = (int) $row['product_id'];
            if ($row['order_date'] < $from) {
                $exports_before[$pid] = ($exports_before[$pid] ?? 0) + (float) $row['qty'];
            } else {
                $exports_in_period[$pid] = ($exports_in_period[$pid] ?? 0) + (float) $row['qty'];
            }
        }

        $rows = [];
        foreach ($products as $p) {
            $pid = (int) $p['id'];

            $qty_in  = (float) ($imports_in_period[$pid] ?? 0);
            $qty_out = (float) ($exports_in_period[$pid] ?? 0);
            $closing = (float) $p['current_stock'];

            // Inventory identity:
            //   closing = (imports_before - exports_before) + qty_in - qty_out
            // So:  opening = closing - qty_in + qty_out + imports_before - exports_before
            // This gives the exact stock level at the START of the date range.
            $imports_b = $imports_before[$pid] ?? 0;
            $exports_b = $exports_before[$pid] ?? 0;
            $opening   = max(0, $closing - $qty_in + $qty_out + $imports_b - $exports_b);

            $rows[] = [
                'sku'           => $p['sku'],
                'name'          => $p['name'],
                'unit'          => $p['unit'],
                'opening_stock' => $opening,
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
             GROUP BY p.id, p.sku, p.name, p.unit
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
