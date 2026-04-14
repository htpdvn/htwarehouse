<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\NumberHelper;

defined('ABSPATH') || exit;

/**
 * Handles return-order (hàng trả lại) AJAX endpoints.
 *
 * Flow:
 *   1. ajax_save_return()    — create/update a PENDING return order (no stock change yet)
 *   2. ajax_confirm_return() — confirm return: restore stock, update export-order totals & status
 *   3. ajax_return_list()    — fetch all return orders linked to a given export order
 *   4. ajax_delete_return()  — delete a PENDING return order
 */
class ReturnOrderPage
{

    // ── AJAX: Save (create/update) pending return order ──────────────────────
    public static function ajax_save_return(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $return_id      = absint($_POST['return_id']      ?? 0);
        $export_order_id = absint($_POST['export_order_id'] ?? 0);
        $return_date    = sanitize_text_field($_POST['return_date'] ?? current_time('Y-m-d'));
        $reason         = sanitize_text_field($_POST['reason'] ?? '');
        $notes          = sanitize_textarea_field($_POST['notes'] ?? '');
        $items_raw      = $_POST['items'] ?? [];

        if (! $export_order_id) wp_send_json_error('Thiếu mã đơn bán.');

        // Verify export order exists and is in returnable status
        $export_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_export_orders WHERE id = %d",
            $export_order_id
        ), ARRAY_A);

        if (! $export_order) wp_send_json_error('Đơn bán không tồn tại.');
        if ('draft' === $export_order['status']) wp_send_json_error('Đơn bán chưa được xác nhận, không thể tạo đơn trả.');
        if ('fully_returned' === $export_order['status']) wp_send_json_error('Đơn bán đã được trả toàn bộ.');

        // If editing, verify it's still pending
        if ($return_id > 0) {
            $existing_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}htw_return_orders WHERE id = %d",
                $return_id
            ));
            if ('confirmed' === $existing_status) {
                wp_send_json_error('Không thể sửa đơn trả đã xác nhận.');
            }
        }

        // Load export items to validate return quantities
        $export_items = $wpdb->get_results($wpdb->prepare(
            "SELECT ei.*, p.name AS product_name
             FROM {$wpdb->prefix}htw_export_items ei
             LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
             WHERE ei.order_id = %d",
            $export_order_id
        ), ARRAY_A);

        // Build a map of export_item_id → remaining returnable qty
        // (already-confirmed returns must be deducted)
        $already_returned = self::get_confirmed_returned_qty($export_order_id, $return_id);

        $export_item_map = [];
        foreach ($export_items as $ei) {
            $export_item_map[$ei['id']] = $ei;
        }

        // Validate and build return items
        $items = [];
        foreach ($items_raw as $row) {
            $export_item_id = absint($row['export_item_id'] ?? 0);
            $qty_returned   = (float) ($row['qty_returned'] ?? 0);

            if (! $export_item_id || $qty_returned <= 0) continue;

            if (! isset($export_item_map[$export_item_id])) {
                wp_send_json_error('Sản phẩm không thuộc đơn bán này.');
            }

            $ei             = $export_item_map[$export_item_id];
            $sold_qty       = (float) $ei['qty'];
            $returned_so_far = (float) ($already_returned[$export_item_id] ?? 0);
            $max_returnable  = $sold_qty - $returned_so_far;

            if ($qty_returned > $max_returnable) {
                wp_send_json_error(
                    "Sản phẩm \"{$ei['product_name']}\": số lượng trả ({$qty_returned}) vượt quá số có thể trả ({$max_returnable})."
                );
            }

            $items[] = [
                'export_item_id' => $export_item_id,
                'product_id'     => (int) $ei['product_id'],
                'qty_returned'   => $qty_returned,
                'sale_price'     => (float) $ei['sale_price'],
                'cogs_per_unit'  => (float) $ei['cogs_per_unit'],
                'product_name'   => $ei['product_name'],
            ];
        }

        if (empty($items)) wp_send_json_error('Đơn trả phải có ít nhất 1 sản phẩm.');

        // Calculate totals
        $total_qty      = '0';
        $total_refund   = '0';
        $total_cogs_back = '0';
        foreach ($items as $item) {
            $qty_str   = (string) $item['qty_returned'];
            $sp_str    = (string) $item['sale_price'];
            $cpu_str   = (string) $item['cogs_per_unit'];
            $total_qty      = NumberHelper::add($total_qty, $qty_str);
            $total_refund   = NumberHelper::add($total_refund, NumberHelper::mul($qty_str, $sp_str));
            $total_cogs_back = NumberHelper::add($total_cogs_back, NumberHelper::mul($qty_str, $cpu_str));
        }

        $return_table = $wpdb->prefix . 'htw_return_orders';
        $ri_table     = $wpdb->prefix . 'htw_return_items';

        $return_data = [
            'export_order_id' => $export_order_id,
            'return_date'     => $return_date,
            'reason'          => $reason,
            'notes'           => $notes,
            'total_qty'       => $total_qty,
            'total_refund'    => $total_refund,
            'total_cogs_back' => $total_cogs_back,
            'status'          => 'pending',
        ];

        if ($return_id > 0) {
            $wpdb->update($return_table, $return_data, ['id' => $return_id]);
            $wpdb->delete($ri_table, ['return_order_id' => $return_id], ['%d']);
        } else {
            // Auto-generate return_code
            $attempts = 0;
            do {
                $return_code = 'RTN-' . strtoupper(bin2hex(random_bytes(3)));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$return_table} WHERE return_code = %s",
                    $return_code
                ));
                $attempts++;
            } while ($exists && $attempts < 10);

            $return_data['return_code'] = $return_code;
        }

        // Wrap header + items in a transaction to prevent partial writes
        // (e.g. return_order inserted but items fail due to FK or quota constraint).
        $wpdb->query('START TRANSACTION');
        try {
            if ($return_id > 0) {
                $wpdb->update($return_table, $return_data, ['id' => $return_id]);
                $wpdb->delete($ri_table, ['return_order_id' => $return_id], ['%d']);
            } else {
                $wpdb->insert($return_table, $return_data);
                $return_id = $wpdb->insert_id;
                if (! $return_id) throw new \Exception('Không thể tạo đơn trả.');
            }

            foreach ($items as $item) {
                $inserted = $wpdb->insert($ri_table, [
                    'return_order_id' => $return_id,
                    'export_item_id'  => $item['export_item_id'],
                    'product_id'      => $item['product_id'],
                    'qty_returned'    => $item['qty_returned'],
                    'sale_price'      => $item['sale_price'],
                    'cogs_per_unit'   => $item['cogs_per_unit'],
                ]);
                if ($inserted === false) throw new \Exception('Không thể lưu sản phẩm vào đơn trả.');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Lưu đơn trả thất bại: ' . $e->getMessage());
        }

        wp_send_json_success([
            'return_id'   => $return_id,
            'return_code' => $return_data['return_code'] ?? $wpdb->get_var($wpdb->prepare(
                "SELECT return_code FROM {$return_table} WHERE id = %d",
                $return_id
            )),
            'message'     => 'Đã lưu đơn trả hàng.',
        ]);
    }

    // ── AJAX: Confirm return order → restore stock ────────────────────────────
    public static function ajax_confirm_return(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $return_id    = absint($_POST['return_id'] ?? 0);
        $return_table = $wpdb->prefix . 'htw_return_orders';
        $ri_table     = $wpdb->prefix . 'htw_return_items';
        $export_table = $wpdb->prefix . 'htw_export_orders';
        $ei_table     = $wpdb->prefix . 'htw_export_items';
        $products_table = $wpdb->prefix . 'htw_products';

        $return_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$return_table} WHERE id = %d",
            $return_id
        ), ARRAY_A);

        if (! $return_order) wp_send_json_error('Đơn trả không tồn tại.');
        if ('confirmed' === $return_order['status']) wp_send_json_error('Đơn trả đã được xác nhận rồi.');

        $return_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$ri_table} WHERE return_order_id = %d",
            $return_id
        ), ARRAY_A);

        if (empty($return_items)) wp_send_json_error('Đơn trả không có sản phẩm.');

        $export_order_id = (int) $return_order['export_order_id'];

        $wpdb->query('START TRANSACTION');

        try {
            // Re-read the return order WITH FOR UPDATE immediately inside the transaction.
            // This prevents a race condition where two concurrent confirm requests both read
            // 'pending' before either writes 'confirmed', causing double stock restoration.
            $return_order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$return_table} WHERE id = %d FOR UPDATE",
                $return_id
            ), ARRAY_A);

            if (! $return_order) {
                throw new \Exception('\u0110\u01a1n tr\u1ea3 kh\u00f4ng t\u1ed3n t\u1ea1i.');
            }
            if ('confirmed' === $return_order['status']) {
                // Another request already confirmed this return order — silently succeed
                // rather than returning an error, preventing duplicate stock additions.
                $wpdb->query('ROLLBACK');
                wp_send_json_success('\u0110\u01a1n tr\u1ea3 \u0111\u00e3 \u0111\u01b0\u1ee3c x\u00e1c nh\u1eadn r\u1ed3i.');
            }

            // Validate return quantities again (race-condition safety)
            // and lock product rows
            $already_returned = self::get_confirmed_returned_qty($export_order_id, $return_id);

            foreach ($return_items as $ri) {
                $ei = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$ei_table} WHERE id = %d",
                    $ri['export_item_id']
                ), ARRAY_A);

                if (! $ei) throw new \Exception("Export item #{$ri['export_item_id']} kh\u00f4ng t\u1ed3n t\u1ea1i.");

                $sold_qty        = (float) $ei['qty'];
                $returned_so_far = (float) ($already_returned[$ri['export_item_id']] ?? 0);
                $max_returnable  = $sold_qty - $returned_so_far;

                if ((float) $ri['qty_returned'] > $max_returnable) {
                    throw new \Exception(
                        "S\u1ed1 l\u01b0\u1ee3ng tr\u1ea3 v\u01b0\u1ee3t gi\u1edbi h\u1ea1n cho s\u1ea3n ph\u1ea9m #{$ri['product_id']}. T\u1ed1i \u0111a: {$max_returnable}"
                    );
                }

                // Restore stock — lock the product row first (read avg_cost too for WAC recalc)
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT current_stock, avg_cost FROM {$products_table} WHERE id = %d FOR UPDATE",
                    $ri['product_id']
                ), ARRAY_A);

                if (! $product) throw new \Exception("S\u1ea3n ph\u1ea9m #{$ri['product_id']} kh\u00f4ng t\u1ed3n t\u1ea1i.");

                $old_stock    = (float) $product['current_stock'];
                $old_avg_cost = (float) $product['avg_cost'];
                $qty_back     = (float) $ri['qty_returned'];
                $cogs_back    = (float) $ri['cogs_per_unit'];

                $new_stock = NumberHelper::add((string) $old_stock, (string) $qty_back);

                // Recalculate WAC: (old_stock × old_avg + qty_back × cogs_back) / new_stock
                // This mirrors CostCalculator::add_stock() logic — treating the return
                // as a new “incoming batch” at the original COGS price.
                $new_avg_cost = $old_avg_cost; // default: unchanged if new_stock is 0 (edge case)
                if ((float) $new_stock > 0) {
                    $numerator    = NumberHelper::add(
                        NumberHelper::mul((string) $old_stock, (string) $old_avg_cost),
                        NumberHelper::mul((string) $qty_back,  (string) $cogs_back)
                    );
                    $new_avg_cost = NumberHelper::div($numerator, $new_stock);
                }

                $ok = $wpdb->update(
                    $products_table,
                    [
                        'current_stock' => $new_stock,
                        'avg_cost'      => $new_avg_cost,
                    ],
                    ['id' => $ri['product_id']],
                    ['%f', '%f'],
                    ['%d']
                );
                if ($ok === false) throw new \Exception("Kh\u00f4ng th\u1ec3 c\u1eadp nh\u1eadt t\u1ed3n kho s\u1ea3n ph\u1ea9m #{$ri['product_id']}.");
            }

            // Mark return order as confirmed
            $ok = $wpdb->update(
                $return_table,
                ['status' => 'confirmed'],
                ['id' => $return_id],
                ['%s'],
                ['%d']
            );
            if ($ok === false) throw new \Exception('Kh\u00f4ng th\u1ec3 c\u1eadp nh\u1eadt tr\u1ea1ng th\u00e1i \u0111\u01a1n tr\u1ea3.');

            // Lock the export_order row to prevent concurrent confirms from racing
            // on the same export order (both would call recalculate with stale data).
            $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$export_table} WHERE id = %d FOR UPDATE",
                $export_order_id
            ));

            // Recalculate export order totals and determine new status
            self::recalculate_export_order($export_order_id);


            $wpdb->query('COMMIT');
            wp_send_json_success('\u0110\u00e3 x\u00e1c nh\u1eadn \u0111\u01a1n tr\u1ea3 h\u00e0ng. T\u1ed3n kho \u0111\u00e3 \u0111\u01b0\u1ee3c ho\u00e0n l\u1ea1i.');

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('X\u00e1c nh\u1eadn th\u1ea5t b\u1ea1i: ' . $e->getMessage());
        }
    }

    // ── AJAX: List return orders for a given export order ────────────────────
    public static function ajax_return_list(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $export_order_id = absint($_POST['export_order_id'] ?? 0);
        if (! $export_order_id) wp_send_json_error('Thiếu mã đơn bán.');

        $returns = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_return_orders
             WHERE export_order_id = %d
             ORDER BY created_at DESC",
            $export_order_id
        ), ARRAY_A);

        foreach ($returns as &$r) {
            $r['items'] = $wpdb->get_results($wpdb->prepare(
                "SELECT ri.*, p.name AS product_name
                 FROM {$wpdb->prefix}htw_return_items ri
                 LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = ri.product_id
                 WHERE ri.return_order_id = %d",
                $r['id']
            ), ARRAY_A);
        }
        unset($r);

        wp_send_json_success($returns);
    }

    // ── AJAX: Delete a PENDING return order ───────────────────────────────────
    public static function ajax_delete_return(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $return_id = absint($_POST['return_id'] ?? 0);
        $status    = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}htw_return_orders WHERE id = %d",
            $return_id
        ));

        if (! $status) wp_send_json_error('Đơn trả không tồn tại.');
        if ('confirmed' === $status) wp_send_json_error('Không thể xoá đơn trả đã xác nhận.');

        $wpdb->delete($wpdb->prefix . 'htw_return_items',  ['return_order_id' => $return_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'htw_return_orders', ['id'              => $return_id], ['%d']);

        wp_send_json_success('Đã xoá đơn trả hàng.');
    }

    // ── Helper: get already-confirmed returned qty per export_item_id ─────────
    /**
     * Returns a map of export_item_id => total_qty_already_confirmed_returned.
     * Optionally excludes a specific return_order_id (for editing).
     *
     * @param int $export_order_id
     * @param int $exclude_return_id  0 = exclude none
     * @return array<int, float>
     */
    private static function get_confirmed_returned_qty(int $export_order_id, int $exclude_return_id = 0): array
    {
        global $wpdb;

        $exclude_clause = $exclude_return_id > 0
            ? $wpdb->prepare(' AND ro.id != %d', $exclude_return_id)
            : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ri.export_item_id, SUM(ri.qty_returned) AS qty_sum
             FROM {$wpdb->prefix}htw_return_items ri
             INNER JOIN {$wpdb->prefix}htw_return_orders ro ON ro.id = ri.return_order_id
             WHERE ro.export_order_id = %d
               AND ro.status = 'confirmed'
               {$exclude_clause}
             GROUP BY ri.export_item_id",
            $export_order_id
        ), ARRAY_A);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['export_item_id']] = (float) $row['qty_sum'];
        }
        return $map;
    }

    // ── Helper: recalculate export order totals after a return is confirmed ───
    /**
     * Adjusts total_revenue, total_cogs, total_profit on the export order
     * to reflect all confirmed returns. Then updates status to:
     *   - 'partial_return'   if some items still returned partially
     *   - 'fully_returned'   if all sold qty has been returned
     */
    private static function recalculate_export_order(int $export_order_id): void
    {
        global $wpdb;

        $export_table = $wpdb->prefix . 'htw_export_orders';
        $ei_table     = $wpdb->prefix . 'htw_export_items';
        $ri_table     = $wpdb->prefix . 'htw_return_items';
        $ro_table     = $wpdb->prefix . 'htw_return_orders';

        // Sum of all confirmed returns for this export order
        $confirmed_returns = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(ro.total_refund),    0) AS sum_refund,
                COALESCE(SUM(ro.total_cogs_back), 0) AS sum_cogs_back,
                COALESCE(SUM(ro.total_qty),       0) AS sum_qty
             FROM {$ro_table} ro
             WHERE ro.export_order_id = %d
               AND ro.status = 'confirmed'",
            $export_order_id
        ), ARRAY_A);

        // Original locked totals (from all export items)
        $orig = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(revenue), 0) AS orig_revenue,
                COALESCE(SUM(cogs),    0) AS orig_cogs,
                COALESCE(SUM(qty),     0) AS orig_qty
             FROM {$ei_table}
             WHERE order_id = %d",
            $export_order_id
        ), ARRAY_A);

        $net_revenue = NumberHelper::sub((string) $orig['orig_revenue'], (string) $confirmed_returns['sum_refund']);
        $net_cogs    = NumberHelper::sub((string) $orig['orig_cogs'],    (string) $confirmed_returns['sum_cogs_back']);
        $net_profit  = NumberHelper::sub($net_revenue, $net_cogs);

        // Determine new status: check per-item whether each line is fully returned.
        // Using total qty SUM is semantically incorrect when items have different units
        // (e.g. 1 thùng + 1 cái = 2 units, but they are not comparable).
        // Instead: an order is 'fully_returned' only if EVERY export_item has been 100% returned.
        $per_item_status = $wpdb->get_results($wpdb->prepare(
            "SELECT ei.id,
                    ei.qty                                                          AS sold_qty,
                    COALESCE(SUM(ri.qty_returned), 0)                              AS returned_qty
             FROM {$ei_table} ei
             LEFT JOIN {$ri_table} ri
               ON ri.export_item_id = ei.id
               AND ri.return_order_id IN (
                     SELECT id FROM {$ro_table}
                     WHERE export_order_id = %d AND status = 'confirmed'
                   )
             WHERE ei.order_id = %d
             GROUP BY ei.id, ei.qty",
            $export_order_id,
            $export_order_id
        ), ARRAY_A);

        $total_returned_qty = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ri.qty_returned), 0)
             FROM {$ri_table} ri
             INNER JOIN {$ro_table} ro ON ro.id = ri.return_order_id
             WHERE ro.export_order_id = %d
               AND ro.status = 'confirmed'",
            $export_order_id
        ));

        if ($total_returned_qty <= 0) {
            $new_status = 'confirmed';
        } else {
            $all_fully_returned = ! empty($per_item_status) && array_reduce(
                $per_item_status,
                fn($carry, $row) => $carry && ((float) $row['returned_qty'] >= (float) $row['sold_qty']),
                true
            );
            $new_status = $all_fully_returned ? 'fully_returned' : 'partial_return';
        }

        $wpdb->update(
            $export_table,
            [
                'total_revenue' => $net_revenue,
                'total_cogs'    => $net_cogs,
                'total_profit'  => $net_profit,
                'status'        => $new_status,
            ],
            ['id' => $export_order_id],
            ['%f', '%f', '%f', '%s'],
            ['%d']
        );
    }
}
