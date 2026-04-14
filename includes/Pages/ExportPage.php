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
        $order_date    = sanitize_text_field($_POST['order_date'] ?? current_time('Y-m-d'));
        $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
        $notes         = sanitize_textarea_field($_POST['notes'] ?? '');
        $items_raw     = $_POST['items'] ?? [];

        if (! in_array($channel, ['facebook', 'tiktok', 'shopee', 'other'], true)) {
            $channel = 'other';
        }

        $orders_table = $wpdb->prefix . 'htw_export_orders';

        if (empty($order_code)) {
            // Auto-generate order code with retry on collision
            $attempts = 0;
            do {
                $order_code = 'ORD-' . strtoupper(bin2hex(random_bytes(3)));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$orders_table} WHERE order_code = %s",
                    $order_code
                ));
                $attempts++;
            } while ($exists && $attempts < 10);
            if ($exists) {
                wp_send_json_error('Không thể tạo mã đơn không trùng lặp. Vui lòng nhập mã đơn thủ công.');
            }
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

            ActivityLogger::log(
                'confirm',
                'export_order',
                $id,
                $order->order_code,
                'Xác nhận đơn xuất kho ' . $order->order_code . ' (' . count($items) . ' sản phẩm) — tồn kho đã trừ'
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

        $wpdb->delete($wpdb->prefix . 'htw_export_items',  ['order_id' => $id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'htw_export_orders', ['id' => $id],        ['%d']);

        ActivityLogger::log('delete', 'export_order', $id, $order_code, 'Xóa đơn xuất kho nháp ' . $order_code);

        wp_send_json_success('Đã xoá đơn hàng.');
    }
}
