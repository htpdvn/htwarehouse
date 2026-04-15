<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\ActivityLogger;

defined('ABSPATH') || exit;

class SuppliersPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        include HTW_PLUGIN_DIR . 'templates/suppliers/list.php';
    }

    public static function ajax_save(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htw_suppliers';

        $id           = absint($_POST['id'] ?? 0);
        $supplier_code = sanitize_text_field($_POST['supplier_code'] ?? '');
        $name         = sanitize_text_field($_POST['name'] ?? '');
        $contact_name = sanitize_text_field($_POST['contact_name'] ?? '');
        $phone        = sanitize_text_field($_POST['phone'] ?? '');
        $email        = sanitize_email($_POST['email'] ?? '');
        $address      = sanitize_textarea_field($_POST['address'] ?? '');
        $tax_code     = sanitize_text_field($_POST['tax_code'] ?? '');
        $notes        = sanitize_textarea_field($_POST['notes'] ?? '');

        if (empty($name)) {
            wp_send_json_error('Tên nhà cung cấp không được để trống.');
        }

        // Auto-generate supplier_code if empty
        if (empty($supplier_code)) {
            $attempts = 0;
            do {
                $supplier_code = 'NCC-' . strtoupper(bin2hex(random_bytes(2)));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE supplier_code = %s AND id != %d",
                    $supplier_code, $id
                ));
                $attempts++;
            } while ($exists && $attempts < 10);
        } else {
            // Validate uniqueness of manually entered code
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE supplier_code = %s AND id != %d",
                $supplier_code, $id
            ));
            if ($exists) {
                wp_send_json_error('Mã NCC "' . $supplier_code . '" đã tồn tại. Vui lòng dùng mã khác.');
            }
        }

        $data = compact('supplier_code', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_code', 'notes');
        $fmt  = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($id > 0) {
            $updated = $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
            if ($updated === false) {
                wp_send_json_error('Lỗi cập nhật: ' . $wpdb->last_error);
            }
            ActivityLogger::log('update', 'supplier', $id, $supplier_code, 'Cập nhật nhà cung cấp: ' . $name . ' [' . $supplier_code . ']');
            wp_send_json_success(['message' => 'Đã cập nhật nhà cung cấp.']);
        } else {
            $inserted = $wpdb->insert($table, $data, $fmt);
            if ($inserted === false) {
                wp_send_json_error('Lỗi thêm mới: ' . $wpdb->last_error);
            }
            $new_id = $wpdb->insert_id;
            ActivityLogger::log('create', 'supplier', $new_id, $supplier_code, 'Tạo mới nhà cung cấp: ' . $name . ' [' . $supplier_code . ']');
            wp_send_json_success(['id' => $new_id, 'supplier_code' => $supplier_code, 'message' => 'Đã thêm nhà cung cấp.']);
        }
    }

    public static function ajax_delete(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        if (! $id) {
            wp_send_json_error('Invalid ID');
        }

        $table = $wpdb->prefix . 'htw_suppliers';

        // Check if supplier is used in any PO
        $used = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}htw_purchase_orders WHERE supplier_id = %d",
            $id
        ));
        if ($used > 0) {
            wp_send_json_error("Nhà cung cấp này đang được sử dụng trong {$used} đơn đặt hàng. Không thể xoá.");
        }

        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
        if ($deleted === false) {
            wp_send_json_error('Lỗi xoá: ' . $wpdb->last_error);
        }
        ActivityLogger::log('delete', 'supplier', $id, '', 'Xóa nhà cung cấp ID=' . $id);
        wp_send_json_success(['message' => 'Đã xoá nhà cung cấp.']);
    }

    public static function ajax_transactions(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $supplier_id = absint($_POST['supplier_id'] ?? 0);
        if (! $supplier_id) {
            wp_send_json_error('Invalid supplier ID');
        }

        // Supplier info
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_suppliers WHERE id = %d",
            $supplier_id
        ), ARRAY_A);

        if (! $supplier) {
            wp_send_json_error('Không tìm thấy nhà cung cấp.');
        }

        // Purchase orders for this supplier
        $pos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, po_code, order_date, status, goods_total, total_amount, amount_paid, amount_remaining
             FROM {$wpdb->prefix}htw_purchase_orders
             WHERE supplier_id = %d
             ORDER BY order_date DESC, id DESC",
            $supplier_id
        ), ARRAY_A);

        $po_ids = array_column($pos, 'id');

        // Payment records for all orders
        $payments = [];
        if (! empty($po_ids)) {
            $placeholders = implode(',', array_fill(0, count($po_ids), '%d'));
            $payments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.*, po.po_code
                     FROM {$wpdb->prefix}htw_po_payments p
                     JOIN {$wpdb->prefix}htw_purchase_orders po ON po.id = p.po_id
                     WHERE p.po_id IN ($placeholders)
                     ORDER BY p.payment_date DESC, p.id DESC",
                    ...$po_ids
                ),
                ARRAY_A
            );
        }

        // Totals
        $total_amount    = array_sum(array_column($pos, 'total_amount'));
        $total_paid      = array_sum(array_column($pos, 'amount_paid'));
        $total_remaining = array_sum(array_column($pos, 'amount_remaining'));

        wp_send_json_success([
            'supplier'        => $supplier,
            'pos'             => $pos,
            'payments'        => $payments,
            'total_amount'    => $total_amount,
            'total_paid'      => $total_paid,
            'total_remaining' => $total_remaining,
        ]);
    }
}
