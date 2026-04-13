<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\CostCalculator;
use HTWarehouse\Services\NumberHelper;

defined('ABSPATH') || exit;

class ImportPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) wp_die('Unauthorized');

        global $wpdb;
        $suppliers = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}htw_suppliers ORDER BY name",
            ARRAY_A
        );

        include HTW_PLUGIN_DIR . 'templates/imports/list.php';
    }

    // ── AJAX: Save draft batch ────────────────────────────────────────────────
    public static function ajax_save(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $id           = absint($_POST['id'] ?? 0);
        $batch_code   = sanitize_text_field($_POST['batch_code'] ?? '');
        $supplier_id  = absint($_POST['supplier_id'] ?? 0) ?: null;
        $supplier     = sanitize_text_field($_POST['supplier'] ?? '');
        $import_date  = sanitize_text_field($_POST['import_date'] ?? current_time('Y-m-d'));
        $shipping_fee    = (float) ($_POST['shipping_fee'] ?? 0);
        $tax_fee         = (float) ($_POST['tax_fee'] ?? 0);
        $service_fee     = (float) ($_POST['service_fee'] ?? 0);
        $inspection_fee  = (float) ($_POST['inspection_fee'] ?? 0);
        $packing_fee     = (float) ($_POST['packing_fee'] ?? 0);
        $other_fee       = (float) ($_POST['other_fee'] ?? 0);
        $notes        = sanitize_textarea_field($_POST['notes'] ?? '');
        $items_raw    = $_POST['items'] ?? [];

        if (empty($batch_code)) {
            // Auto-generate batch code with retry on collision
            $batch_table = $wpdb->prefix . 'htw_import_batches';
            $attempts = 0;
            do {
                $batch_code = 'IMP-' . strtoupper(bin2hex(random_bytes(3)));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$batch_table} WHERE batch_code = %s",
                    $batch_code
                ));
                $attempts++;
            } while ($exists && $attempts < 10);
            if ($exists) {
                wp_send_json_error('Không thể tạo mã lô không trùng lặp. Vui lòng nhập mã lô thủ công.');
            }
        } else {
            $batch_table = $wpdb->prefix . 'htw_import_batches';
            // Verify user-provided code is not duplicated
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$batch_table} WHERE batch_code = %s AND id != %d",
                $batch_code,
                $id > 0 ? $id : 0
            ));
            if ($exists) {
                wp_send_json_error('Mã lô "' . $batch_code . '" đã tồn tại. Vui lòng dùng mã khác.');
            }
        }

        // Parse items
        $items = [];
        foreach ($items_raw as $row) {
            $pid = absint($row['product_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            $up  = (float) ($row['unit_price'] ?? 0);
            if ($pid && $qty > 0) {
                $items[] = ['product_id' => $pid, 'qty' => $qty, 'unit_price' => $up];
            }
        }

        if (empty($items)) {
            wp_send_json_error('Lô hàng phải có ít nhất 1 sản phẩm.');
        }

        $batch_table = $wpdb->prefix . 'htw_import_batches';
        $items_table = $wpdb->prefix . 'htw_import_items';

        $batch_data = [
            'batch_code'     => $batch_code,
            'supplier_id'   => $supplier_id,
            'supplier'      => $supplier,
            'import_date'    => $import_date,
            'shipping_fee'   => $shipping_fee,
            'tax_fee'        => $tax_fee,
            'service_fee'    => $service_fee,
            'inspection_fee' => $inspection_fee,
            'packing_fee'    => $packing_fee,
            'other_fee'      => $other_fee,
            'notes'          => $notes,
            'status'         => 'draft',
        ];

        if ($id > 0) {
            // Only allow editing drafts
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$batch_table} WHERE id = %d", $id));
            if ('confirmed' === $status) {
                wp_send_json_error('Không thể sửa lô hàng đã xác nhận lưu kho.');
            }
            $wpdb->update($batch_table, $batch_data, ['id' => $id]);
            $wpdb->delete($items_table, ['batch_id' => $id]);
        } else {
            $wpdb->insert($batch_table, $batch_data);
            $id = $wpdb->insert_id;
        }

        // Insert items (no cost allocation yet — happens on confirm)
        foreach ($items as $item) {
            $wpdb->insert($items_table, [
                'batch_id'   => $id,
                'product_id' => $item['product_id'],
                'qty'        => $item['qty'],
                'unit_price' => $item['unit_price'],
                'total_cost' => $item['qty'] * $item['unit_price'],
            ]);
        }

        wp_send_json_success(['id' => $id, 'batch_code' => $batch_code, 'message' => 'Đã lưu lô hàng.']);
    }

    // ── AJAX: Confirm batch → update stock & WAC ──────────────────────────────
    public static function ajax_confirm(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id          = absint($_POST['id'] ?? 0);
        $batch_table = $wpdb->prefix . 'htw_import_batches';
        $items_table = $wpdb->prefix . 'htw_import_items';

        $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$batch_table} WHERE id = %d", $id));
        if (! $batch) {
            wp_send_json_error('Lô hàng không tồn tại.');
        }
        if ('confirmed' === $batch->status) {
            wp_send_json_error('Lô hàng đã được xác nhận rồi.');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT ii.*, p.name AS product_name
             FROM {$items_table} ii
             JOIN {$wpdb->prefix}htw_products p ON p.id = ii.product_id
             WHERE ii.batch_id = %d",
            $id
        ), ARRAY_A);

        if (empty($items)) {
            wp_send_json_error('Lô hàng không có sản phẩm.');
        }

        $extra_cost = (float) $batch->shipping_fee
            + (float) $batch->tax_fee
            + (float) $batch->service_fee
            + (float) $batch->inspection_fee
            + (float) $batch->packing_fee
            + (float) $batch->other_fee;

        // Allocate extra costs BEFORE starting transaction so that the PHP array
        // is ready before any DB write happens.
        $items = CostCalculator::allocate_extra_costs($items, $extra_cost);

        // Begin transaction FIRST — all subsequent FOR UPDATE locks are then covered by it.
        // This ordering eliminates the race window that existed between the old lock
        // loop and the START TRANSACTION call.
        $wpdb->query('START TRANSACTION');

        try {
            // Acquire row-level locks for all affected product rows.
            $locked = [];
            foreach ($items as $item) {
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT current_stock, avg_cost FROM {$wpdb->prefix}htw_products WHERE id = %d FOR UPDATE",
                    (int) $item['product_id']
                ), ARRAY_A);
                $locked[(int) $item['product_id']] = $product;
            }

            // Update WAC and stock for each product
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];

                // Update the item with allocated cost
                $updated = $wpdb->update($items_table, [
                    'allocated_cost_per_unit' => $item['allocated_cost_per_unit'],
                    'total_cost'              => $item['total_cost'],
                ], ['id' => $item['id']]);

                if ($updated === false) {
                    throw new \Exception('Không thể cập nhật item: ' . $item['id']);
                }

                // Apply WAC using locked values — no additional SELECT needed.
                CostCalculator::add_stock(
                    $pid,
                    (string) $locked[$pid]['current_stock'],
                    (string) $locked[$pid]['avg_cost'],
                    (string) $item['qty'],
                    (string) $item['allocated_cost_per_unit']
                );
            }

            // Mark batch as confirmed
            $updated = $wpdb->update($batch_table, ['status' => 'confirmed'], ['id' => $id]);
            if ($updated === false) {
                throw new \Exception('Không thể cập nhật trạng thái lô hàng: ' . $id);
            }

            $wpdb->query('COMMIT');
            wp_send_json_success('Lô hàng đã được xác nhận. Kho hàng đã được cập nhật.');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Xác nhận thất bại. Vui lòng thử lại. Chi tiết: ' . $e->getMessage());
        }
    }

    // ── AJAX: Delete draft batch ──────────────────────────────────────────────
    public static function ajax_delete(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}htw_import_batches WHERE id = %d", $id));

        if ('confirmed' === $status) {
            wp_send_json_error('Không thể xoá lô hàng đã xác nhận lưu kho.');
        }

        $wpdb->delete($wpdb->prefix . 'htw_import_items',   ['batch_id' => $id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'htw_import_batches', ['id' => $id],        ['%d']);

        wp_send_json_success('Đã xoá lô hàng.');
    }
}
