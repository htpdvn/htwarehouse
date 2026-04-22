<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\ActivityLogger;
use HTWarehouse\Services\CostCalculator;
use HTWarehouse\Services\NumberHelper;

defined('ABSPATH') || exit;

class ExportPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) wp_die('Unauthorized');
        include HTW_PLUGIN_DIR . 'templates/exports/list.php';
    }

    // ── AJAX: Save draft order ─────────────────────────────────────────────────
    public static function ajax_save(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $id            = absint($_POST['id'] ?? 0);
        $order_code    = sanitize_text_field($_POST['order_code'] ?? '');
        $channel       = sanitize_text_field($_POST['channel'] ?? 'other');
        // SEC-01 fix: validate date format to prevent invalid dates being stored in DB
        $order_date    = \HTWarehouse\Services\NumberHelper::validate_date(
            sanitize_text_field($_POST['order_date'] ?? '')
        );
        $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
        $notes         = sanitize_textarea_field($_POST['notes'] ?? '');
        $items_raw     = $_POST['items'] ?? [];

        if (! in_array($channel, ['facebook', 'tiktok', 'shopee', 'other'], true)) {
            $channel = 'other';
        }

        $orders_table = $wpdb->prefix . 'htw_export_orders';

        if (empty($order_code)) {
            // BUG-NEW-01 fix: try INSERT directly, retry on Duplicate Entry error.
            // Eliminates race between SELECT-check and INSERT.
            $attempts = 0;
            $ok = false;
            do {
                $order_code = 'ORD-' . strtoupper(bin2hex(random_bytes(3)));
                $ok = $wpdb->insert($orders_table, ['order_code' => $order_code]) !== false;
                if (! $ok && stripos($wpdb->last_error, 'duplicate') === false) {
                    wp_send_json_error('Lỗi tạo mã đơn: ' . $wpdb->last_error);
                }
                $attempts++;
            } while (! $ok && $attempts < 10);
            if (! $ok) {
                wp_send_json_error('Không thể tạo mã đơn không trùng lặp. Vui lòng nhập mã đơn thủ công.');
            }
            // Rollback dummy row — real insert happens inside the transaction below.
            $wpdb->query("DELETE FROM {$orders_table} WHERE id = {$wpdb->insert_id}");
        } else {
            // Verify user-provided code is not duplicated
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$orders_table} WHERE order_code = %s AND id != %d",
                $order_code,
                $id > 0 ? $id : 0
            ));
            if ($exists) {
                wp_send_json_error('Mã đơn "' . $order_code . '" đã tồn tại. Vui lòng dùng mã khác.');
            }
        }

        // Parse items
        $items = [];
        foreach ($items_raw as $row) {
            $pid = absint($row['product_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            $sp  = (float) ($row['sale_price'] ?? 0);
            if ($pid && $qty > 0) {
                $items[] = ['product_id' => $pid, 'qty' => $qty, 'sale_price' => $sp];
            }
        }

        if (empty($items)) {
            wp_send_json_error('Đơn hàng phải có ít nhất 1 sản phẩm.');
        }

        $items_table  = $wpdb->prefix . 'htw_export_items';

        $order_data = [
            'order_code'    => $order_code,
            'channel'       => $channel,
            'order_date'    => $order_date,
            'customer_name' => $customer_name,
            'notes'         => $notes,
            'status'        => 'draft',
        ];

        // Wrap the write operations in a transaction so that a crash between
        // DELETE-items and INSERT-items cannot leave the order in an empty state.
        if ($id > 0) {
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$orders_table} WHERE id = %d", $id));
            if (in_array($status, ['confirmed', 'partial_return', 'fully_returned'], true)) {
                wp_send_json_error('Không thể sửa đơn hàng đã xác nhận.');
            }
        }

        $wpdb->query('START TRANSACTION');
        try {
            if ($id > 0) {
                $wpdb->update($orders_table, $order_data, ['id' => $id]);
                $wpdb->delete($items_table, ['order_id' => $id]);
            } else {
                $wpdb->insert($orders_table, $order_data);
                $id = $wpdb->insert_id;
                if (! $id) throw new \Exception('Không thể tạo đơn hàng.');
            }

            // Grab current avg_cost for each product — only used to pre-fill the
            // order form preview. COGS/revenue/profit are NOT stored for drafts to
            // avoid stale data when avg_cost changes before confirm.
            // Order totals are left at 0 until confirmed (when COGS is locked).
            $total_revenue = '0';
            $total_cogs    = '0';
            $total_profit  = '0';
            foreach ($items as $item) {
                $avg_cost = $wpdb->get_var($wpdb->prepare(
                    "SELECT avg_cost FROM {$wpdb->prefix}htw_products WHERE id = %d",
                    $item['product_id']
                ));
                $avg_str = (string) $avg_cost;
                $qty_str = (string) $item['qty'];
                $sp_str  = (string) $item['sale_price'];

                $revenue_str = NumberHelper::mul($qty_str, $sp_str);
                $cogs_str    = NumberHelper::mul($qty_str, $avg_str);
                $profit_str  = NumberHelper::sub($revenue_str, $cogs_str);

                $total_revenue = NumberHelper::add($total_revenue, $revenue_str);
                $total_cogs    = NumberHelper::add($total_cogs, $cogs_str);
                $total_profit  = NumberHelper::add($total_profit, $profit_str);

                $inserted = $wpdb->insert($items_table, [
                    'order_id'   => $id,
                    'product_id' => $item['product_id'],
                    'qty'        => $item['qty'],
                    'sale_price' => $item['sale_price'],
                    // cogs_per_unit, revenue, cogs, profit left NULL for drafts —
                    // they are only populated on confirm to avoid stale preview data.
                ]);
                if ($inserted === false) throw new \Exception('Không thể lưu sản phẩm vào đơn hàng.');
            }

            // Update order totals — display-only for drafts (not locked COGS).
            // On confirm, they are overwritten with the authoritative locked values.
            $wpdb->update($orders_table, [
                'total_revenue' => $total_revenue,
                'total_cogs'    => $total_cogs,
                'total_profit'  => $total_profit,
            ], ['id' => $id]);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Lưu đơn hàng thất bại: ' . $e->getMessage());
        }

        $is_update  = isset($_POST['id']) && absint($_POST['id']) > 0;
        $log_action = $is_update ? 'update' : 'create';
        $log_verb   = $is_update ? 'Cập nhật' : 'Tạo mới';
        ActivityLogger::log(
            $log_action,
            'export_order',
            $id,
            $order_code,
            "{$log_verb} đơn xuất kho {$order_code} (" . count($items) . " sản phẩm)"
        );

        wp_send_json_success(['id' => $id, 'order_code' => $order_code, 'message' => 'Đã lưu đơn hàng.']);
    }

    // ── AJAX: Confirm order → deduct stock ────────────────────────────────────
    public static function ajax_confirm(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id           = absint($_POST['id'] ?? 0);
        $orders_table = $wpdb->prefix . 'htw_export_orders';
        $items_table  = $wpdb->prefix . 'htw_export_items';

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders_table} WHERE id = %d", $id));
        if (! $order) wp_send_json_error('Đơn hàng không tồn tại.');
        if (in_array($order->status, ['confirmed', 'partial_return', 'fully_returned'], true)) {
            wp_send_json_error('Đơn hàng đã được xác nhận, không thể xác nhận lại.');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT ei.*, p.name AS product_name, p.current_stock
             FROM {$items_table} ei
             LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             WHERE ei.order_id = %d",
            $id
        ), ARRAY_A);

        // Early exit if no items
        if (empty($items)) wp_send_json_error('Đơn hàng không có sản phẩm.');

        // Begin transaction — wraps all DB writes for atomicity
        $wpdb->query('START TRANSACTION');

        try {
            // Acquire row-level locks for all affected products BEFORE reading stock & avg_cost.
            // This prevents race conditions where two concurrent confirms both see
            // the same stock/cost and both proceed to oversell or use stale avg_cost.
            $stock_check = [];
            $cost_check = [];
            foreach ($items as $item) {
                $pid       = (int) $item['product_id'];
                $locked_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT current_stock, avg_cost FROM {$wpdb->prefix}htw_products WHERE id = %d FOR UPDATE",
                    $pid
                ), ARRAY_A);
                $stock_check[$pid] = (float) $locked_row['current_stock'];
                $cost_check[$pid]  = (float) $locked_row['avg_cost'];

                if ($stock_check[$pid] < (float) $item['qty']) {
                    throw new \Exception("Không đủ hàng trong kho: {$item['product_name']} (tồn: {$stock_check[$pid]}, cần: {$item['qty']})");
                }
            }

            $total_revenue = '0';
            $total_cogs    = '0';

            foreach ($items as $item) {
                $pid   = (int) $item['product_id'];
                // Pass as string to match deduct_stock()'s bcmath-aware signature
                $cogs_per_unit = CostCalculator::deduct_stock(
                    $pid,
                    (string) $stock_check[$pid],
                    (string) $cost_check[$pid],
                    (string) $item['qty']
                );
                $qty_str     = (string) $item['qty'];
                $sp_str      = (string) $item['sale_price'];
                $cpu_str     = (string) $cogs_per_unit;
                $revenue_str = NumberHelper::mul($qty_str, $sp_str);
                $cogs_str    = NumberHelper::mul($qty_str, $cpu_str);
                $profit_str  = NumberHelper::sub($revenue_str, $cogs_str);

                $updated = $wpdb->update($items_table, [
                    'cogs_per_unit' => $cogs_per_unit,
                    'cogs'          => $cogs_str,
                    'revenue'       => $revenue_str,
                    'profit'        => $profit_str,
                ], ['id' => $item['id']]);

                if ($updated === false) {
                    throw new \Exception('Không thể cập nhật item: ' . $item['id']);
                }

                $total_revenue = NumberHelper::add($total_revenue, $revenue_str);
                $total_cogs    = NumberHelper::add($total_cogs, $cogs_str);
            }

            $total_profit = NumberHelper::sub($total_revenue, $total_cogs);

            $updated = $wpdb->update($orders_table, [
                'status'        => 'confirmed',
                'total_revenue' => $total_revenue,
                'total_cogs'    => $total_cogs,
                'total_profit'  => $total_profit,
            ], ['id' => $id]);

            if ($updated === false) {
                throw new \Exception('Không thể cập nhật đơn hàng: ' . $id);
            }

            $wpdb->query('COMMIT');

            // Build financial snapshots for audit trail
            $snap_before_stock = [];
            foreach ($stock_check as $pid => $s) {
                $snap_before_stock['p' . $pid] = ['stock' => $s, 'avg_cost' => $cost_check[$pid]];
            }
            $snap_before = [
                'total_revenue' => (float) $order->total_revenue,
                'total_cogs'    => (float) $order->total_cogs,
                'total_profit'  => (float) $order->total_profit,
                'stock'         => $snap_before_stock,
            ];
            $snap_after_stock = [];
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $snap_after_stock['p' . $pid] = [
                    'stock'    => (float) $stock_check[$pid] - (float) $item['qty'],
                    'avg_cost' => $cost_check[$pid],
                ];
            }
            $snap_after = [
                'total_revenue' => (float) $total_revenue,
                'total_cogs'    => (float) $total_cogs,
                'total_profit'  => (float) $total_profit,
                'profit_check'  => round((float)$total_revenue - (float)$total_cogs, 2),
                'stock'         => $snap_after_stock,
            ];

            ActivityLogger::log(
                'confirm',
                'export_order',
                $id,
                $order->order_code,
                'Xác nhận đơn xuất kho ' . $order->order_code . ' (' . count($items) . ' sản phẩm) — tồn kho đã trừ',
                $snap_before,
                $snap_after
            );

            wp_send_json_success('Đơn hàng đã xác nhận. Kho hàng đã được trừ.');

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Xác nhận thất bại. Vui lòng thử lại. Chi tiết: ' . $e->getMessage());
        }
    }

    // ── AJAX: Get confirmed order detail ─────────────────────────────────────
    public static function ajax_export_detail(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_export_orders WHERE id = %d",
            $id
        ), ARRAY_A);

        if (! $order) wp_send_json_error('Đơn hàng không tồn tại.');

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT ei.*, p.name AS product_name
             FROM {$wpdb->prefix}htw_export_items ei
             LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             WHERE ei.order_id = %d",
            $id
        ), ARRAY_A);

        $order['items'] = $items;
        wp_send_json_success($order);
    }

    // ── AJAX: Delete draft order ──────────────────────────────────────────────
    public static function ajax_delete(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}htw_export_orders WHERE id = %d", $id));

        if (in_array($status, ['confirmed', 'partial_return', 'fully_returned'], true)) {
            wp_send_json_error('Không thể xoá đơn hàng đã xác nhận.');
        }

        $order_code = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT order_code FROM {$wpdb->prefix}htw_export_orders WHERE id = %d", $id
        ));

        // BUG-05 fix: wrap both deletes in a transaction so that if the second
        // DELETE fails, the first DELETE is rolled back — order not left orphaned.
        $wpdb->query('START TRANSACTION');
        $ok1 = $wpdb->delete($wpdb->prefix . 'htw_export_items',  ['order_id' => $id], ['%d']);
        $ok2 = $wpdb->delete($wpdb->prefix . 'htw_export_orders', ['id' => $id],        ['%d']);
        if ($ok1 === false || $ok2 === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Xóa đơn hàng thất bại: ' . $wpdb->last_error);
        }
        $wpdb->query('COMMIT');

        ActivityLogger::log('delete', 'export_order', $id, $order_code, 'Xóa đơn xuất kho nháp ' . $order_code);

        wp_send_json_success('Đã xoá đơn hàng.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  XUẤT KHO HỎNG / WRITE-OFF
    // ══════════════════════════════════════════════════════════════════════════

    // Reason labels for display
    private static function writeoff_reason_label(string $reason): string
    {
        $labels = [
            'damaged'   => 'Hàng bể/hỏng',
            'expired'   => 'Hàng hết hạn',
            'defective' => 'Lỗi nhà sản xuất',
            'obsolete'  => 'Hàng ứ đọng',
            'lost'      => 'Mất / hao hụt',
            'other'     => 'Khác',
        ];
        return $labels[$reason] ?? $reason;
    }

    // ── AJAX: List write-off orders ──────────────────────────────────────────
    public static function ajax_writeoff_list(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $orders = $wpdb->get_results(
            "SELECT wo.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}htw_writeoff_items WHERE writeoff_id = wo.id) AS item_count
             FROM {$wpdb->prefix}htw_writeoff_orders wo
             ORDER BY wo.writeoff_date DESC, wo.id DESC
             LIMIT 200",
            ARRAY_A
        );

        foreach ($orders as &$o) {
            $o['items'] = $wpdb->get_results($wpdb->prepare(
                "SELECT wi.*, p.name AS product_name
                 FROM {$wpdb->prefix}htw_writeoff_items wi
                 LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = wi.product_id
                 WHERE wi.writeoff_id = %d",
                $o['id']
            ), ARRAY_A);
            $o['reason_label'] = self::writeoff_reason_label($o['reason']);
        }
        unset($o);

        wp_send_json_success($orders);
    }

    // ── AJAX: Save (create/update) draft write-off order ────────────────────
    public static function ajax_writeoff_save(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $id           = absint($_POST['id'] ?? 0);
        $writeoff_date = NumberHelper::validate_date(sanitize_text_field($_POST['writeoff_date'] ?? ''));
        $reason       = sanitize_text_field($_POST['reason'] ?? 'damaged');
        $notes        = sanitize_textarea_field($_POST['notes'] ?? '');
        $items_raw    = $_POST['items'] ?? [];

        $allowed_reasons = ['damaged', 'expired', 'defective', 'obsolete', 'lost', 'other'];
        if (! in_array($reason, $allowed_reasons, true)) $reason = 'damaged';

        $items = [];
        foreach ($items_raw as $row) {
            $pid = absint($row['product_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            if ($pid && $qty > 0) $items[] = ['product_id' => $pid, 'qty' => $qty];
        }

        if (empty($items)) wp_send_json_error('Phiếu xuất kho hỏng phải có ít nhất 1 sản phẩm.');

        $wo_table = $wpdb->prefix . 'htw_writeoff_orders';
        $wi_table = $wpdb->prefix . 'htw_writeoff_items';

        $total_qty  = '0';
        $total_cogs = '0';

        $wpdb->query('START TRANSACTION');

        // BUG-WO-03 fix: check + lock status INSIDE the transaction to prevent
        // a TOCTOU race where two concurrent requests both read 'draft' before
        // either has committed, then both proceed to overwrite an already-confirmed order.
        if ($id > 0) {
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wo_table} WHERE id = %d FOR UPDATE",
                $id
            ));
            if ($status === 'confirmed') {
                $wpdb->query('ROLLBACK');
                wp_send_json_error('Không thể sửa phiếu đã xác nhận.');
            }
        }
        try {
            $wo_data = [
                'writeoff_date' => $writeoff_date,
                'reason'        => $reason,
                'notes'         => $notes,
                'status'        => 'draft',
            ];

            if ($id > 0) {
                $wpdb->update($wo_table, $wo_data, ['id' => $id]);
                $wpdb->delete($wi_table, ['writeoff_id' => $id]);
            } else {
                // Generate unique writeoff_code — insert directly, retry on duplicate.
                $attempts = 0;
                $ok = false;
                do {
                    $writeoff_code = 'WRO-' . strtoupper(bin2hex(random_bytes(3)));
                    $ok = $wpdb->insert($wo_table, array_merge($wo_data, ['writeoff_code' => $writeoff_code])) !== false;
                    if (! $ok && stripos($wpdb->last_error, 'duplicate') === false) {
                        throw new \Exception('Lỗi tạo mã phiếu: ' . $wpdb->last_error);
                    }
                    $attempts++;
                } while (! $ok && $attempts < 10);
                if (! $ok) {
                    throw new \Exception('Không thể tạo mã phiếu không trùng lặp.');
                }
                $id = $wpdb->insert_id;
            }

            foreach ($items as $item) {
                $avg_cost = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT avg_cost FROM {$wpdb->prefix}htw_products WHERE id = %d",
                    $item['product_id']
                ));
                $qty_str = (string) $item['qty'];
                $line_cogs = NumberHelper::mul($qty_str, $avg_cost);
                $total_qty  = NumberHelper::add($total_qty, $qty_str);
                $total_cogs = NumberHelper::add($total_cogs, $line_cogs);

                $wpdb->insert($wi_table, [
                    'writeoff_id'   => $id,
                    'product_id'    => $item['product_id'],
                    'qty'           => $item['qty'],
                    'cogs_per_unit' => $avg_cost,
                    'total_cogs'    => $line_cogs,
                ]);
            }

            $wpdb->update($wo_table, [
                'total_qty'  => $total_qty,
                'total_cogs' => $total_cogs,
            ], ['id' => $id]);

            $wpdb->query('COMMIT');

            $code = $wpdb->get_var($wpdb->prepare("SELECT writeoff_code FROM {$wo_table} WHERE id = %d", $id));
            $is_update = isset($_POST['id']) && absint($_POST['id']) > 0;
            ActivityLogger::log(
                $is_update ? 'update' : 'create',
                'writeoff_order',
                $id,
                $code,
                ($is_update ? 'Cập nhật' : 'Tạo mới') . " phiếu xuất kho hỏng {$code} (" . count($items) . " sản phẩm)"
            );

            wp_send_json_success(['id' => $id, 'writeoff_code' => $code, 'message' => 'Đã lưu phiếu xuất kho hỏng.']);

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Lưu phiếu thất bại: ' . $e->getMessage());
        }
    }

    // ── AJAX: Confirm write-off order → deduct stock ──────────────────────────
    public static function ajax_writeoff_confirm(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id       = absint($_POST['id'] ?? 0);
        $wo_table = $wpdb->prefix . 'htw_writeoff_orders';
        $wi_table = $wpdb->prefix . 'htw_writeoff_items';
        $p_table  = $wpdb->prefix . 'htw_products';

        $wo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wo_table} WHERE id = %d", $id), ARRAY_A);
        if (! $wo) wp_send_json_error('Phiếu không tồn tại.');
        if ($wo['status'] === 'confirmed') wp_send_json_error('Phiếu đã được xác nhận.');

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT wi.*, p.name AS product_name
             FROM {$wi_table} wi
             LEFT JOIN {$p_table} p ON p.id = wi.product_id
             WHERE wi.writeoff_id = %d",
            $id
        ), ARRAY_A);
        if (empty($items)) wp_send_json_error('Phiếu không có sản phẩm.');

        $wpdb->query('START TRANSACTION');
        try {
            // FOR UPDATE lock on all affected product rows
            $stock_before = [];
            $cost_before  = [];
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT current_stock, avg_cost FROM {$p_table} WHERE id = %d FOR UPDATE",
                    $pid
                ), ARRAY_A);
                $stock_before[$pid] = (float) $row['current_stock'];
                $cost_before[$pid]  = (float) $row['avg_cost'];

                if ($stock_before[$pid] < (float) $item['qty']) {
                    throw new \Exception("Không đủ hàng trong kho: {$item['product_name']} (tồn: {$stock_before[$pid]}, cần: {$item['qty']})");
                }
            }

            $locked_total_cogs = '0';

            foreach ($items as $item) {
                $pid   = (int) $item['product_id'];
                $qty   = (string) $item['qty'];
                $stock = (string) $stock_before[$pid];

                $new_stock = NumberHelper::comp($stock, $qty, 4) >= 0
                    ? NumberHelper::sub($stock, $qty)
                    : '0';

                $wpdb->update(
                    $p_table,
                    ['current_stock' => $new_stock],
                    ['id' => $pid],
                    ['%s'],
                    ['%d']
                );

                // BUG-WO-01 fix: re-snapshot cogs_per_unit from the FOR-UPDATE-locked
                // avg_cost at confirm time, overwriting the potentially-stale value that
                // was saved when the draft was first created. This ensures the recorded
                // "cost lost" matches the actual WAC at the moment goods left inventory.
                // Under WAC, avg_cost itself is unchanged by stock deductions (correct);
                // only current_stock decreases. We only need to lock in the right cost.
                $locked_avg  = (string) $cost_before[$pid];
                $line_cogs   = NumberHelper::mul($qty, $locked_avg);
                $locked_total_cogs = NumberHelper::add($locked_total_cogs, $line_cogs);

                $wpdb->update(
                    $wi_table,
                    [
                        'cogs_per_unit' => $locked_avg,
                        'total_cogs'    => $line_cogs,
                    ],
                    ['id' => $item['id']],
                    ['%s', '%s'],
                    ['%d']
                );
            }

            $wpdb->update(
                $wo_table,
                [
                    'status'     => 'confirmed',
                    'total_cogs' => $locked_total_cogs,
                ],
                ['id' => $id],
                ['%s', '%s'],
                ['%d']
            );
            $wpdb->query('COMMIT');

            // Audit snapshot
            $snap_before = [];
            foreach ($stock_before as $pid => $s) {
                $snap_before['p' . $pid] = ['stock' => $s, 'avg_cost' => $cost_before[$pid]];
            }
            $snap_after = [];
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $snap_after['p' . $pid] = [
                    'stock'    => (float) $stock_before[$pid] - (float) $item['qty'],
                    'avg_cost' => $cost_before[$pid],
                ];
            }

            ActivityLogger::log(
                'confirm',
                'writeoff_order',
                $id,
                $wo['writeoff_code'],
                'Xác nhận phiếu xuất kho hỏng ' . $wo['writeoff_code'] . ' (' . count($items) . ' sản phẩm) — tồn kho đã trừ',
                $snap_before,
                $snap_after
            );

            wp_send_json_success('Đã xác nhận phiếu xuất kho hỏng. Kho hàng đã được trừ.');

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Xác nhận thất bại: ' . $e->getMessage());
        }
    }

    // ── AJAX: Get write-off order detail ─────────────────────────────────────
    public static function ajax_writeoff_detail(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);

        $wo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_writeoff_orders WHERE id = %d", $id
        ), ARRAY_A);
        if (! $wo) wp_send_json_error('Phiếu không tồn tại.');

        $wo['items'] = $wpdb->get_results($wpdb->prepare(
            "SELECT wi.*, p.name AS product_name
             FROM {$wpdb->prefix}htw_writeoff_items wi
             LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = wi.product_id
             WHERE wi.writeoff_id = %d",
            $id
        ), ARRAY_A);
        $wo['reason_label'] = self::writeoff_reason_label($wo['reason']);

        wp_send_json_success($wo);
    }

    // ── AJAX: Delete draft write-off order ───────────────────────────────────
    public static function ajax_writeoff_delete(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}htw_writeoff_orders WHERE id = %d", $id));

        if ($status === 'confirmed') wp_send_json_error('Không thể xoá phiếu đã xác nhận.');

        $code = (string) $wpdb->get_var($wpdb->prepare("SELECT writeoff_code FROM {$wpdb->prefix}htw_writeoff_orders WHERE id = %d", $id));

        $wpdb->query('START TRANSACTION');
        $ok1 = $wpdb->delete($wpdb->prefix . 'htw_writeoff_items',  ['writeoff_id' => $id]);
        $ok2 = $wpdb->delete($wpdb->prefix . 'htw_writeoff_orders', ['id' => $id]);
        if ($ok1 === false || $ok2 === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Xóa phiếu thất bại: ' . $wpdb->last_error);
        }
        $wpdb->query('COMMIT');

        ActivityLogger::log('delete', 'writeoff_order', $id, $code, 'Xóa phiếu xuất kho hỏng nháp ' . $code);

        wp_send_json_success('Đã xoá phiếu xuất kho hỏng.');
    }
}
