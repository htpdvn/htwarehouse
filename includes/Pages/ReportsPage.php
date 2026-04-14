<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\PdfService;

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
            case 'product_performance':
                wp_send_json_success(self::report_product_performance($date_from, $date_to));
                break;
            default:
                wp_send_json_error('Unknown report type');
        }
    }

    // ── AJAX: xuất PDF ────────────────────────────────────────────────────────
    public static function ajax_export_pdf(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        $report    = sanitize_text_field($_POST['report']    ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to']   ?? '');

        $allowed = ['stock', 'movement', 'profit_by_product', 'profit_by_channel', 'product_performance'];
        if (! in_array($report, $allowed, true)) {
            wp_send_json_error('Loại báo cáo không hợp lệ.');
        }

        $data = self::get_report_data($report, $date_from, $date_to);
        $currency = defined('HTW_CURRENCY_SYMBOL') ? HTW_CURRENCY_SYMBOL : 'VND';

        try {
            $pdf = PdfService::generate($report, $date_from, $date_to, $data, $currency);
        } catch (\Throwable $e) {
            wp_send_json_error('Lỗi tạo PDF: ' . $e->getMessage());
        }

        $titles = [
            'stock'               => 'Ton-kho',
            'movement'            => 'Nhap-Xuat-Ton',
            'profit_by_product'   => 'Loi-nhuan-SP',
            'profit_by_channel'   => 'Loi-nhuan-Kenh',
            'product_performance' => 'Hieu-suat-SP',
        ];
        $filename = ($titles[$report] ?? 'report') . '_' . date('Ymd') . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store, no-cache');
        echo $pdf;
        exit;
    }

    /**
     * Internal helper — fetch raw report data without JSON wrapping.
     */
    private static function get_report_data(string $report, string $from, string $to): array
    {
        switch ($report) {
            case 'stock':
                return self::report_current_stock();
            case 'movement':
                return self::report_movement($from, $to);
            case 'profit_by_product':
                return self::report_profit_product($from, $to);
            case 'profit_by_channel':
                return self::report_profit_channel($from, $to);
            case 'product_performance':
                return self::report_product_performance($from, $to);
            default:
                return [];
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

        // Only load IN-PERIOD transactions. We do NOT need pre-period data because
        // opening_stock is derived from the inventory identity:
        //
        //   closing = opening + qty_in - qty_out   (net of returns)
        //   => opening = closing - qty_in + qty_out
        //
        // `closing` (= current_stock from DB) already encodes ALL historical
        // transactions, so adding pre-period imports/exports would double-count them.

        $confirmed_imports = $wpdb->get_results($wpdb->prepare(
            "SELECT ii.product_id, SUM(ii.qty) AS qty
             FROM {$wpdb->prefix}htw_import_batches ib
             JOIN {$wpdb->prefix}htw_import_items ii ON ii.batch_id = ib.id
             WHERE ib.status = 'confirmed'
               AND ib.import_date BETWEEN %s AND %s
             GROUP BY ii.product_id",
            $from, $to
        ), ARRAY_A);

        $confirmed_exports = $wpdb->get_results($wpdb->prepare(
            "SELECT ei.product_id, SUM(ei.qty) AS qty
             FROM {$wpdb->prefix}htw_export_orders eo
             JOIN {$wpdb->prefix}htw_export_items ei ON ei.order_id = eo.id
             WHERE eo.status IN ('confirmed', 'partial_return', 'fully_returned')
               AND eo.order_date BETWEEN %s AND %s
             GROUP BY ei.product_id",
            $from, $to
        ), ARRAY_A);

        // Confirmed returns IN-PERIOD (by sale date) — to subtract from qty_out
        // so that net qty_out = sold_in_period - returned_in_period (matched by sale date).
        // Using sale date (eo.order_date) keeps returns paired with their originating sale.
        $confirmed_returns = $wpdb->get_results($wpdb->prepare(
            "SELECT ri.product_id, SUM(ri.qty_returned) AS qty_ret
             FROM {$wpdb->prefix}htw_return_items ri
             INNER JOIN {$wpdb->prefix}htw_return_orders ro
                     ON ro.id = ri.return_order_id AND ro.status = 'confirmed'
             INNER JOIN {$wpdb->prefix}htw_export_orders eo
                     ON eo.id = ro.export_order_id
             WHERE eo.order_date BETWEEN %s AND %s
             GROUP BY ri.product_id",
            $from, $to
        ), ARRAY_A);

        $products = $wpdb->get_results(
            "SELECT id, sku, name, unit, current_stock, avg_cost
             FROM {$wpdb->prefix}htw_products ORDER BY name",
            ARRAY_A
        );

        // Index by product_id for O(1) lookup
        $qty_in_map  = [];
        $qty_out_map = [];
        $qty_ret_map = [];

        foreach ($confirmed_imports as $row) {
            $qty_in_map[(int) $row['product_id']] = (float) $row['qty'];
        }
        foreach ($confirmed_exports as $row) {
            $qty_out_map[(int) $row['product_id']] = (float) $row['qty'];
        }
        foreach ($confirmed_returns as $row) {
            $qty_ret_map[(int) $row['product_id']] = (float) $row['qty_ret'];
        }

        $rows = [];
        $has_discrepancy = false;
        foreach ($products as $p) {
            $pid = (int) $p['id'];

            $qty_in      = $qty_in_map[$pid]  ?? 0.0;
            $qty_sold    = $qty_out_map[$pid]  ?? 0.0;
            $qty_ret     = $qty_ret_map[$pid]  ?? 0.0;
            // NET out = sold in period - returns from in-period sales
            $qty_out     = max(0.0, $qty_sold - $qty_ret);
            $closing     = (float) $p['current_stock'];

            // Opening derived from inventory identity:
            //   closing = opening + qty_in - qty_out
            //   opening = closing - qty_in + qty_out
            // This is always mathematically consistent with closing because
            // current_stock already reflects all historical + in-period changes.
            $raw_opening = $closing - $qty_in + $qty_out;

            // Negative opening means stock went below zero during the period
            // (data integrity issue — flag but still display 0).
            if ($raw_opening < 0) {
                $has_discrepancy = true;
            }
            $opening = max(0.0, $raw_opening);

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

        return ['rows' => $rows, 'date_from' => $from, 'date_to' => $to, 'has_discrepancy' => $has_discrepancy];
    }

    // ── Lợi nhuận theo sản phẩm ──────────────────────────────────────────────

    private static function report_profit_product(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        // Net revenue/cogs/profit/qty after subtracting confirmed returns.
        // Without this, orders with partial_return or fully_returned status would
        // inflate revenue, COGS and profit figures.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.sku, p.name, p.unit,
                    SUM(ei.qty)                                                     AS gross_qty,
                    COALESCE(SUM(ret.qty_ret), 0)                                  AS returned_qty,
                    SUM(ei.qty) - COALESCE(SUM(ret.qty_ret), 0)                    AS total_qty,
                    SUM(ei.revenue)  - COALESCE(SUM(ret.refund_amt),  0)           AS total_revenue,
                    SUM(ei.cogs)     - COALESCE(SUM(ret.cogs_back),   0)           AS total_cogs,
                    SUM(ei.profit)   - COALESCE(SUM(ret.profit_back), 0)           AS total_profit,
                    CASE WHEN (SUM(ei.revenue) - COALESCE(SUM(ret.refund_amt), 0)) > 0
                         THEN ROUND(
                             (SUM(ei.profit) - COALESCE(SUM(ret.profit_back), 0))
                             / (SUM(ei.revenue) - COALESCE(SUM(ret.refund_amt), 0))
                             * 100, 2)
                         ELSE 0 END AS margin_pct
             FROM {$wpdb->prefix}htw_export_items ei
             JOIN {$wpdb->prefix}htw_export_orders eo ON eo.id = ei.order_id
             JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             LEFT JOIN (
                 SELECT ri.export_item_id,
                        SUM(ri.qty_returned)                          AS qty_ret,
                        SUM(ri.qty_returned * ri.sale_price)          AS refund_amt,
                        SUM(ri.qty_returned * ri.cogs_per_unit)       AS cogs_back,
                        SUM(ri.qty_returned * (ri.sale_price - ri.cogs_per_unit)) AS profit_back
                 FROM {$wpdb->prefix}htw_return_items ri
                 INNER JOIN {$wpdb->prefix}htw_return_orders ro
                         ON ro.id = ri.return_order_id AND ro.status = 'confirmed'
                 GROUP BY ri.export_item_id
             ) ret ON ret.export_item_id = ei.id
             WHERE eo.status IN ('confirmed', 'partial_return', 'fully_returned')
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

        // Báo cáo theo kênh bán — dùng total_revenue/cogs/profit từ export_orders (đã được
        // recalculate_export_order() điều chỉnh net sau hàng trả).
        // total_orders chỉ đếm đơn có doanh thu thực sự (không tính fully_returned).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT channel,
                    COUNT(CASE WHEN status IN ('confirmed', 'partial_return') THEN 1 END) AS total_orders,
                    SUM(total_revenue) AS revenue,
                    SUM(total_cogs)    AS cogs,
                    SUM(total_profit)  AS profit,
                    CASE WHEN SUM(total_revenue) > 0
                         THEN ROUND(SUM(total_profit) / SUM(total_revenue) * 100, 2)
                         ELSE 0 END AS margin_pct
             FROM {$wpdb->prefix}htw_export_orders
             WHERE status IN ('confirmed', 'partial_return', 'fully_returned')
               AND order_date BETWEEN %s AND %s
             GROUP BY channel
             ORDER BY profit DESC",
            $from,
            $to
        ), ARRAY_A);

        return ['rows' => $rows, 'date_from' => $from, 'date_to' => $to];
    }

    // ── Hiệu suất dòng sản phẩm ──────────────────────────────────────────────
    /**
     * Composite Product Performance Scorecard.
     *
     * Tính Performance Score (0–100) cho từng sản phẩm dựa trên:
     *   - score_turnover  (35%): Vòng quay vốn = qty_net_sold / avg_stock_in_period
     *   - score_margin    (30%): Biên lợi nhuận % so với max của kỳ
     *   - score_profit    (25%): Tổng lợi nhuận tuyệt đối so với max của kỳ
     *   - score_return    (10%): 1 - tỷ lệ trả hàng (hàng ít bị trả = điểm cao)
     */
    private static function report_product_performance(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        // ── Bước 1: Lấy doanh số thuần (net of returns) theo product ────────
        $sales_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id AS product_id,
                    p.sku,
                    p.name,
                    p.unit,
                    p.current_stock,
                    p.avg_cost,
                    COALESCE(SUM(ei.qty), 0)                                          AS gross_qty_sold,
                    COALESCE(SUM(ret.qty_ret), 0)                                     AS qty_returned,
                    COALESCE(SUM(ei.qty), 0) - COALESCE(SUM(ret.qty_ret), 0)          AS net_qty_sold,
                    COALESCE(SUM(ei.revenue), 0)  - COALESCE(SUM(ret.refund_amt), 0)  AS net_revenue,
                    COALESCE(SUM(ei.cogs), 0)     - COALESCE(SUM(ret.cogs_back), 0)   AS net_cogs,
                    COALESCE(SUM(ei.profit), 0)   - COALESCE(SUM(ret.profit_back), 0) AS net_profit,
                    CASE
                        WHEN (COALESCE(SUM(ei.revenue), 0) - COALESCE(SUM(ret.refund_amt), 0)) > 0
                        THEN ROUND(
                            (COALESCE(SUM(ei.profit), 0) - COALESCE(SUM(ret.profit_back), 0))
                            / (COALESCE(SUM(ei.revenue), 0) - COALESCE(SUM(ret.refund_amt), 0))
                            * 100, 2)
                        ELSE 0
                    END AS margin_pct,
                    CASE
                        WHEN COALESCE(SUM(ei.qty), 0) > 0
                        THEN ROUND(COALESCE(SUM(ret.qty_ret), 0) / COALESCE(SUM(ei.qty), 0) * 100, 2)
                        ELSE 0
                    END AS return_rate_pct
             FROM {$wpdb->prefix}htw_products p
             LEFT JOIN {$wpdb->prefix}htw_export_items ei
                    ON ei.product_id = p.id
             LEFT JOIN {$wpdb->prefix}htw_export_orders eo
                    ON eo.id = ei.order_id
                   AND eo.status IN ('confirmed','partial_return','fully_returned')
                   AND eo.order_date BETWEEN %s AND %s
             LEFT JOIN (
                 SELECT ri.export_item_id,
                        SUM(ri.qty_returned)                                   AS qty_ret,
                        SUM(ri.qty_returned * ri.sale_price)                    AS refund_amt,
                        SUM(ri.qty_returned * ri.cogs_per_unit)                 AS cogs_back,
                        SUM(ri.qty_returned * (ri.sale_price - ri.cogs_per_unit)) AS profit_back
                 FROM {$wpdb->prefix}htw_return_items ri
                 INNER JOIN {$wpdb->prefix}htw_return_orders ro
                         ON ro.id = ri.return_order_id AND ro.status = 'confirmed'
                 GROUP BY ri.export_item_id
             ) ret ON ret.export_item_id = ei.id
             GROUP BY p.id, p.sku, p.name, p.unit, p.current_stock, p.avg_cost",
            $from,
            $to
        ), ARRAY_A);

        // ── Bước 2: Lấy qty nhập kho trong kỳ để tính opening stock ─────────
        $imports_in_period = $wpdb->get_results($wpdb->prepare(
            "SELECT ii.product_id, SUM(ii.qty) AS qty_in
             FROM {$wpdb->prefix}htw_import_batches ib
             JOIN {$wpdb->prefix}htw_import_items ii ON ii.batch_id = ib.id
             WHERE ib.status = 'confirmed'
               AND ib.import_date BETWEEN %s AND %s
             GROUP BY ii.product_id",
            $from,
            $to
        ), ARRAY_A);
        $qty_in_map = [];
        foreach ($imports_in_period as $r) {
            $qty_in_map[(int)$r['product_id']] = (float)$r['qty_in'];
        }

        // ── Bước 3: Tính turnover & bổ sung derived fields ──────────────────
        $rows = [];
        foreach ($sales_rows as $r) {
            $pid           = (int)$r['product_id'];
            $net_qty_sold  = max(0.0, (float)$r['net_qty_sold']);
            $closing_stock = (float)$r['current_stock'];
            $qty_in        = $qty_in_map[$pid] ?? 0.0;
            $qty_out_net   = max(0.0, (float)$r['gross_qty_sold'] - (float)$r['qty_returned']);

            // Opening = closing - qty_in + qty_out_net (inventory identity)
            $opening_stock = max(0.0, $closing_stock - $qty_in + $qty_out_net);
            $avg_stock     = ($opening_stock + $closing_stock) / 2.0;

            // Inventory Turnover Ratio (ITR)
            // If avg_stock = 0 but we sold something → treat as very high turnover (new stock)
            if ($avg_stock > 0) {
                $turnover = $net_qty_sold / $avg_stock;
            } elseif ($net_qty_sold > 0) {
                $turnover = $net_qty_sold; // proxy: sold qty as the rate
            } else {
                $turnover = 0.0;
            }

            $rows[] = [
                'product_id'      => $pid,
                'sku'             => $r['sku'],
                'name'            => $r['name'],
                'unit'            => $r['unit'],
                'net_qty_sold'    => $net_qty_sold,
                'qty_returned'    => (float)$r['qty_returned'],
                'net_revenue'     => (float)$r['net_revenue'],
                'net_cogs'        => (float)$r['net_cogs'],
                'net_profit'      => (float)$r['net_profit'],
                'margin_pct'      => (float)$r['margin_pct'],
                'return_rate_pct' => (float)$r['return_rate_pct'],
                'opening_stock'   => round($opening_stock, 2),
                'closing_stock'   => round($closing_stock, 2),
                'turnover'        => round($turnover, 2),
                'performance_score' => 0, // filled below
                'recommendation'    => '',
            ];
        }

        // ── Bước 4: Normalize & tính Performance Score ───────────────────────
        $max_turnover = max(array_column($rows, 'turnover') ?: [0]);
        $max_margin   = max(array_column($rows, 'margin_pct') ?: [0]);
        // Only consider positive profit products for normalisation
        $profits       = array_filter(array_column($rows, 'net_profit'), fn($v) => $v > 0);
        $max_profit    = $profits ? max($profits) : 1;

        foreach ($rows as &$row) {
            // Only score products that had actual sales in this period
            if ($row['net_qty_sold'] <= 0 && $row['net_revenue'] <= 0) {
                $row['performance_score'] = 0;
                $row['recommendation']    = 'no_sales';
                continue;
            }

            $s_turnover = $max_turnover > 0  ? ($row['turnover']   / $max_turnover * 100) : 0;
            $s_margin   = $max_margin   > 0  ? ($row['margin_pct'] / $max_margin   * 100) : 0;
            $s_profit   = $max_profit   > 0  ? (max(0, $row['net_profit']) / $max_profit * 100) : 0;
            // Return rate penalty: 0% return = 100pts, 100% return = 0pts
            $s_return   = max(0, 100 - $row['return_rate_pct']);

            $score = round(
                ($s_turnover * 0.35)
              + ($s_margin   * 0.30)
              + ($s_profit   * 0.25)
              + ($s_return   * 0.10)
            , 1);

            $row['performance_score'] = $score;
            if ($score >= 70) {
                $row['recommendation'] = 'increase';  // Tăng vốn
            } elseif ($score >= 40) {
                $row['recommendation'] = 'maintain';  // Duy trì
            } else {
                $row['recommendation'] = 'review';    // Xem xét lại
            }
        }
        unset($row);

        // Sort by performance_score DESC
        usort($rows, fn($a, $b) => $b['performance_score'] <=> $a['performance_score']);

        // ── Bước 5: Tính summary cards ───────────────────────────────────────
        $sold_rows = array_filter($rows, fn($r) => $r['net_qty_sold'] > 0 || $r['net_revenue'] > 0);

        $top_score    = $sold_rows ? array_values($sold_rows)[0] : null;
        $top_turnover = $sold_rows ? array_reduce(
            array_values($sold_rows),
            fn($carry, $r) => (!$carry || $r['turnover'] > $carry['turnover']) ? $r : $carry
        ) : null;
        $top_profit   = $sold_rows ? array_reduce(
            array_values($sold_rows),
            fn($carry, $r) => (!$carry || $r['net_profit'] > $carry['net_profit']) ? $r : $carry
        ) : null;
        // Worst = lowest score among products with actual sales
        $worst = $sold_rows ? array_reduce(
            array_values($sold_rows),
            fn($carry, $r) => (!$carry || $r['performance_score'] < $carry['performance_score']) ? $r : $carry
        ) : null;

        return [
            'rows'        => array_values($rows),
            'date_from'   => $from,
            'date_to'     => $to,
            'top_cards'   => [
                'top_score'    => $top_score,
                'top_turnover' => $top_turnover,
                'top_profit'   => $top_profit,
                'worst'        => $worst,
            ],
        ];
    }
}
