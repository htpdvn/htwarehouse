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
            case 'supplier_scorecard':
                wp_send_json_success(self::report_supplier_scorecard($date_from, $date_to));
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

        $allowed = ['stock', 'movement', 'profit_by_product', 'profit_by_channel', 'product_performance', 'supplier_scorecard'];
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
            case 'supplier_scorecard':
                return self::report_supplier_scorecard($from, $to);
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

    // ── Đánh giá Nhà Cung Cấp ────────────────────────────────────────────────
    /**
     * Supplier Scorecard Report.
     *
     * So sánh các nhà cung cấp theo:
     *   - Tổng chi phí landing (tiền hàng + tất cả phí)
     *   - Thời gian giao hàng (ngày đặt → ngày lô nhập được confirm)
     *
     * Chỉ tính PO có status IN ('received','paid_off') và lô nhập đã confirmed.
     */
    private static function report_supplier_scorecard(string $from, string $to): array
    {
        global $wpdb;

        if (empty($from)) $from = date('Y-m-01');
        if (empty($to))   $to   = date('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                COALESCE(po.supplier_id, 0)                                                        AS supplier_id,
                COALESCE(NULLIF(TRIM(s.name),''), NULLIF(TRIM(po.supplier_name),''), '(Kh\u00f4ng r\u00f5)') AS supplier_name,
                COUNT(DISTINCT po.id)                                                              AS total_orders,
                COALESCE(SUM(ii_agg.total_qty), 0)                                                 AS total_qty,
                SUM(po.goods_total)                                                                AS total_goods,
                SUM(po.shipping_fee + po.tax_fee + po.service_fee
                    + po.inspection_fee + po.packing_fee + po.other_fee)                           AS total_fees,
                SUM(po.goods_total + po.shipping_fee + po.tax_fee + po.service_fee
                    + po.inspection_fee + po.packing_fee + po.other_fee)                           AS total_landed_cost,
                AVG(CASE WHEN ib.status = 'confirmed'
                    THEN DATEDIFF(DATE(ib.updated_at), po.order_date) END)                         AS avg_lead_time_days,
                MIN(CASE WHEN ib.status = 'confirmed'
                    THEN DATEDIFF(DATE(ib.updated_at), po.order_date) END)                         AS min_lead_time_days,
                MAX(CASE WHEN ib.status = 'confirmed'
                    THEN DATEDIFF(DATE(ib.updated_at), po.order_date) END)                         AS max_lead_time_days,
                COUNT(DISTINCT CASE WHEN ib.status = 'confirmed' THEN po.id END)                   AS received_orders
             FROM {$wpdb->prefix}htw_purchase_orders po
             LEFT JOIN {$wpdb->prefix}htw_suppliers s ON s.id = po.supplier_id
             LEFT JOIN {$wpdb->prefix}htw_import_batches ib ON ib.id = po.import_batch_id
             LEFT JOIN (
                 SELECT batch_id, SUM(qty) AS total_qty
                 FROM {$wpdb->prefix}htw_import_items
                 GROUP BY batch_id
             ) ii_agg ON ii_agg.batch_id = po.import_batch_id
             WHERE po.status IN ('received','paid_off')
               AND po.order_date BETWEEN %s AND %s
             GROUP BY COALESCE(po.supplier_id, 0)
             ORDER BY avg_lead_time_days ASC, total_landed_cost ASC",
            $from,
            $to
        ), ARRAY_A);

        // Tính derived fields
        foreach ($rows as &$row) {
            $total_qty         = (float) $row['total_qty'];
            $total_landed_cost = (float) $row['total_landed_cost'];
            $total_fees        = (float) $row['total_fees'];
            $total_orders      = (int)   $row['total_orders'];

            $row['avg_cost_per_unit'] = ($total_qty > 0)
                ? round($total_landed_cost / $total_qty, 2)
                : 0.0;

            $row['fee_ratio_pct'] = ($total_landed_cost > 0)
                ? round($total_fees / $total_landed_cost * 100, 1)
                : 0.0;

            // Phí phát sinh trung bình cho mỗi lần đặt hàng
            $row['avg_fee_per_order'] = ($total_orders > 0)
                ? round($total_fees / $total_orders, 0)
                : 0.0;

            // Cast numerics
            $row['total_orders']       = $total_orders;
            $row['received_orders']    = (int)   $row['received_orders'];
            $row['total_qty']          = $total_qty;
            $row['total_goods']        = (float) $row['total_goods'];
            $row['total_fees']         = $total_fees;
            $row['total_landed_cost']  = $total_landed_cost;
            $row['avg_lead_time_days'] = $row['avg_lead_time_days'] !== null ? round((float)$row['avg_lead_time_days'], 1) : null;
            $row['min_lead_time_days'] = $row['min_lead_time_days'] !== null ? (int) $row['min_lead_time_days'] : null;
            $row['max_lead_time_days'] = $row['max_lead_time_days'] !== null ? (int) $row['max_lead_time_days'] : null;
        }
        unset($row);

        // ── So sánh giá theo SKU chung ────────────────────────────────────────
        // Tìm các SKU mua từ ≥2 NCC trong kỳ — phân bổ phí tỉ lệ theo giá trị hàng.
        // Công thức: total_cost_per_unit = avg_unit_price + allocated_fee_per_unit
        //   allocated_fee_per_unit = (goods_value_of_sku / po.goods_total) * po.total_fees / sku_qty
        $sku_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT
                p.id                                                             AS product_id,
                p.sku,
                p.name                                                           AS product_name,
                p.unit,
                COALESCE(po.supplier_id, 0)                                      AS supplier_id,
                COALESCE(NULLIF(TRIM(s.name),''), NULLIF(TRIM(po.supplier_name),''), '(Kh\u00f4ng r\u00f5)') AS supplier_name,
                SUM(poi.qty)                                                     AS total_qty,
                AVG(poi.unit_price)                                              AS avg_unit_price,
                SUM(poi.unit_price * poi.qty)                                    AS goods_value,
                SUM(
                    CASE WHEN po.goods_total > 0
                    THEN (poi.unit_price * poi.qty / po.goods_total)
                         * (po.shipping_fee + po.tax_fee + po.service_fee
                            + po.inspection_fee + po.packing_fee + po.other_fee)
                    ELSE 0 END
                ) / NULLIF(SUM(poi.qty), 0)                                      AS allocated_fee_per_unit
             FROM {$wpdb->prefix}htw_purchase_orders po
             JOIN {$wpdb->prefix}htw_purchase_order_items poi ON poi.po_id = po.id
             JOIN {$wpdb->prefix}htw_products p ON p.id = poi.product_id
             LEFT JOIN {$wpdb->prefix}htw_suppliers s ON s.id = po.supplier_id
             WHERE po.status IN ('received','paid_off')
               AND po.order_date BETWEEN %s AND %s
               AND po.goods_total > 0
             GROUP BY p.id, p.sku, p.name, p.unit, COALESCE(po.supplier_id, 0)
             ORDER BY p.name ASC, avg_unit_price ASC",
            $from,
            $to
        ), ARRAY_A);

        // Nhóm theo product_id, chỉ giữ những SKU xuất hiện ở ≥2 NCC
        $by_product = [];
        foreach ($sku_raw as $sr) {
            $pid = (int) $sr['product_id'];
            if (!isset($by_product[$pid])) {
                $by_product[$pid] = [
                    'product_id'   => $pid,
                    'sku'          => $sr['sku'],
                    'product_name' => $sr['product_name'],
                    'unit'         => $sr['unit'],
                    'suppliers'    => [],
                ];
            }
            $avg_unit     = (float) $sr['avg_unit_price'];
            $fee_per_unit = ($sr['allocated_fee_per_unit'] !== null) ? (float) $sr['allocated_fee_per_unit'] : 0.0;
            $by_product[$pid]['suppliers'][] = [
                'supplier_id'          => (int)   $sr['supplier_id'],
                'supplier_name'        => $sr['supplier_name'],
                'total_qty'            => (float) $sr['total_qty'],
                'avg_unit_price'       => round($avg_unit, 2),
                'allocated_fee_per_unit' => round($fee_per_unit, 2),
                'total_cost_per_unit'  => round($avg_unit + $fee_per_unit, 2),
            ];
        }

        // Lọc chỉ giữ SKU có ≥2 NCC, đánh dấu NCC rẻ nhất
        $sku_comparison = [];
        foreach ($by_product as $item) {
            if (count($item['suppliers']) < 2) continue;

            // Tìm total_cost_per_unit thấp nhất
            $min_cost = min(array_column($item['suppliers'], 'total_cost_per_unit'));
            foreach ($item['suppliers'] as &$sup) {
                $sup['is_cheapest'] = (abs($sup['total_cost_per_unit'] - $min_cost) < 0.01);
            }
            unset($sup);

            // Sắp xếp rẻ nhất lên đầu
            usort($item['suppliers'], fn($a, $b) => $a['total_cost_per_unit'] <=> $b['total_cost_per_unit']);
            $sku_comparison[] = $item;
        }

        // ── KPI Cards ────────────────────────────────────────────────────────
        $with_lead_time = array_filter($rows, fn($r) => $r['avg_lead_time_days'] !== null);

        // Giao nhanh nhất (avg_lead_time_days thấp nhất)
        $fastest = $with_lead_time ? array_reduce(
            array_values($with_lead_time),
            fn($carry, $r) => (!$carry || $r['avg_lead_time_days'] < $carry['avg_lead_time_days']) ? $r : $carry
        ) : null;

        // Phí phát sinh/đơn thấp nhất (so sánh được - không phụ thuộc vào loại hàng)
        $cheapest_fee = $rows ? array_reduce(
            array_values($rows),
            fn($carry, $r) => (!$carry || $r['avg_fee_per_order'] < $carry['avg_fee_per_order']) ? $r : $carry
        ) : null;

        // Nhiều đơn nhất
        $most_orders = $rows ? array_reduce(
            array_values($rows),
            fn($carry, $r) => (!$carry || $r['total_orders'] > $carry['total_orders']) ? $r : $carry
        ) : null;

        return [
            'rows'           => array_values($rows),
            'date_from'      => $from,
            'date_to'        => $to,
            'sku_comparison' => array_values($sku_comparison),
            'kpi'            => [
                'fastest'      => $fastest,
                'cheapest_fee' => $cheapest_fee,
                'most_orders'  => $most_orders,
            ],
        ];
    }
}

