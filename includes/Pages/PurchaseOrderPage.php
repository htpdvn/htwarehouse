<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\NumberHelper;

defined('ABSPATH') || exit;

class PurchaseOrderPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) wp_die('Unauthorized');
        include HTW_PLUGIN_DIR . 'templates/purchase-orders/list.php';
    }

    public static function ajax_save(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;

        $id               = absint($_POST['id'] ?? 0);
        $po_code          = sanitize_text_field($_POST['po_code'] ?? '');
        $supplier_id      = absint($_POST['supplier_id'] ?? 0);
        $supplier_name    = sanitize_text_field($_POST['supplier_name'] ?? '');
        // Auto-populate supplier_name from suppliers table if left blank but supplier_id is set
        if (empty($supplier_name) && $supplier_id > 0) {
            $supplier_name = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}htw_suppliers WHERE id = %d", $supplier_id
            ));
        }
        $supplier_contact = sanitize_text_field($_POST['supplier_contact'] ?? '');
        $supplier_phone   = sanitize_text_field($_POST['supplier_phone'] ?? '');
        $supplier_address = sanitize_textarea_field($_POST['supplier_address'] ?? '');
        $order_date       = sanitize_text_field($_POST['order_date'] ?? current_time('Y-m-d'));
        $shipping_fee     = (float) ($_POST['shipping_fee'] ?? 0);
        $tax_fee          = (float) ($_POST['tax_fee'] ?? 0);
        $service_fee      = (float) ($_POST['service_fee'] ?? 0);
        $inspection_fee   = (float) ($_POST['inspection_fee'] ?? 0);
        $packing_fee      = (float) ($_POST['packing_fee'] ?? 0);
        $other_fee        = (float) ($_POST['other_fee'] ?? 0);
        $notes            = sanitize_textarea_field($_POST['notes'] ?? '');
        $items_raw        = $_POST['items'] ?? [];

        if (empty($po_code)) {
            $po_table = $wpdb->prefix . 'htw_purchase_orders';
            $attempts = 0;
            do {
                $po_code = 'PO-' . strtoupper(bin2hex(random_bytes(3)));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$po_table} WHERE po_code = %s", $po_code
                ));
                $attempts++;
            } while ($exists && $attempts < 10);
            if ($exists) {
                wp_send_json_error('Không thể tạo mã đơn không trùng lặp. Vui lòng nhập mã thủ công.');
            }
        } else {
            $po_table = $wpdb->prefix . 'htw_purchase_orders';
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$po_table} WHERE po_code = %s AND id != %d",
                $po_code, $id > 0 ? $id : 0
            ));
            if ($exists) {
                wp_send_json_error('Mã đơn "' . $po_code . '" đã tồn tại. Vui lòng dùng mã khác.');
            }
        }

        $items = [];
        $goods_total = 0.0;
        foreach ($items_raw as $row) {
            $pid = absint($row['product_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            $up  = (float) ($row['unit_price'] ?? 0);
            if ($pid && $qty > 0) {
                $line_total = $qty * $up;
                $goods_total += $line_total;
                $items[] = ['product_id' => $pid, 'qty' => $qty, 'unit_price' => $up, 'line_total' => $line_total];
            }
        }

        if (empty($items)) {
            wp_send_json_error('Đơn đặt hàng phải có ít nhất 1 sản phẩm.');
        }

        $extra_fees   = $shipping_fee + $tax_fee + $service_fee + $inspection_fee + $packing_fee + $other_fee;
        $total_amount = $goods_total + $extra_fees;

        $amount_paid   = 0.0;
        $current_status = 'draft';

        if ($id > 0) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT status, amount_paid, import_batch_id FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $id
            ), ARRAY_A);
            if ($existing) {
                $current_status = $existing['status'];
                if ($current_status !== 'draft' && $current_status !== 'received') {
                    wp_send_json_error('Chỉ có thể sửa đơn ở trạng thái nháp hoặc đã nhận hàng.');
                }
                $amount_paid = (float) $existing['amount_paid'];

                // Warn if editing a received PO that already has an import batch
                if ($current_status === 'received' && ! empty($existing['import_batch_id'])) {
                    $batch = $wpdb->get_row($wpdb->prepare(
                        "SELECT status FROM {$wpdb->prefix}htw_import_batches WHERE id = %d", $existing['import_batch_id']
                    ));
                    if ($batch && $batch->status === 'draft') {
                        wp_send_json_error('Cảnh báo: Đơn này đã tạo lô nhập kho draft. Việc sửa items sẽ KHÔNG tự động cập nhật lô nhập. Vui lòng vào trang Nhập kho để chỉnh sửa hoặc xóa lô nháp trước.');
                    }
                }
            }
        }

        // Preserve existing status when editing received orders
        $new_status = ($current_status !== 'draft') ? $current_status : 'draft';

        $amount_remaining = max(0, $total_amount - $amount_paid);

        $po_table = $wpdb->prefix . 'htw_purchase_orders';
        $po_data = [
            'po_code'          => $po_code,
            'supplier_id'     => $supplier_id ?: null,
            'supplier_name'    => $supplier_name,
            'supplier_contact' => $supplier_contact,
            'supplier_phone'   => $supplier_phone,
            'supplier_address' => $supplier_address,
            'order_date'       => $order_date,
            'goods_total'      => $goods_total,
            'shipping_fee'     => $shipping_fee,
            'tax_fee'          => $tax_fee,
            'service_fee'      => $service_fee,
            'inspection_fee'   => $inspection_fee,
            'packing_fee'      => $packing_fee,
            'other_fee'        => $other_fee,
            'total_amount'     => $total_amount,
            'amount_paid'      => $amount_paid,
            'amount_remaining' => $amount_remaining,
            'notes'            => $notes,
            'status'           => $new_status,
        ];

        // Wrap all writes in a transaction: if INSERT items fails mid-loop, the
        // header row is rolled back too, preventing an order with no items.
        $wpdb->query('START TRANSACTION');
        try {
            if ($id > 0) {
                $wpdb->update($po_table, $po_data, ['id' => $id]);
                $wpdb->delete($wpdb->prefix . 'htw_purchase_order_items', ['po_id' => $id]);
            } else {
                $wpdb->insert($po_table, $po_data);
                $id = $wpdb->insert_id;
                if (! $id) throw new \Exception('Không thể tạo đơn đặt hàng.');
            }

            $items_table = $wpdb->prefix . 'htw_purchase_order_items';
            foreach ($items as $item) {
                $inserted = $wpdb->insert($items_table, [
                    'po_id'      => $id,
                    'product_id' => $item['product_id'],
                    'qty'        => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                ]);
                if ($inserted === false) throw new \Exception('Không thể lưu sản phẩm vào đơn hàng.');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Lưu đơn hàng thất bại: ' . $e->getMessage());
        }

        wp_send_json_success(['id' => $id, 'po_code' => $po_code, 'message' => 'Đã lưu đơn đặt hàng.']);
    }

    public static function ajax_confirm(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);

        $po = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $id
        ));

        if (! $po) {
            wp_send_json_error('Đơn đặt hàng không tồn tại.');
        }
        if ($po->status !== 'draft') {
            wp_send_json_error('Chỉ có thể gửi đơn ở trạng thái nháp.');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_purchase_order_items WHERE po_id = %d", $id
        ), ARRAY_A);

        if (empty($items)) {
            wp_send_json_error('Đơn đặt hàng không có sản phẩm nào.');
        }

        $wpdb->update($wpdb->prefix . 'htw_purchase_orders', ['status' => 'confirmed'], ['id' => $id]);
        wp_send_json_success('Đơn đặt hàng đã được gửi đến nhà cung cấp.');
    }

    public static function ajax_send_to_import(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);

        $po = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $id
        ));

        if (! $po) {
            wp_send_json_error('Đơn đặt hàng không tồn tại.');
        }
        if (! in_array($po->status, ['confirmed', 'paid_off'], true)) {
            wp_send_json_error('Chỉ có thể chuyển nhập kho khi đơn đã được gửi đến NCC.');
        }
        if (! empty($po->import_batch_id)) {
            wp_send_json_error('Đơn này đã được chuyển nhập kho rồi.');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT poi.*, p.name AS product_name
             FROM {$wpdb->prefix}htw_purchase_order_items poi
             LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = poi.product_id
             WHERE poi.po_id = %d", $id
        ), ARRAY_A);

        $batch_table = $wpdb->prefix . 'htw_import_batches';
        $attempts = 0;
        do {
            $batch_code = $po->po_code . '-IMP-' . strtoupper(bin2hex(random_bytes(2)));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$batch_table} WHERE batch_code = %s", $batch_code
            ));
            $attempts++;
        } while ($exists && $attempts < 10);

        // Resolve supplier name: prefer denormalized field, fall back to suppliers table
        $supplier_name = $po->supplier_name;
        if (empty($supplier_name) && ! empty($po->supplier_id)) {
            $supplier_name = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}htw_suppliers WHERE id = %d", $po->supplier_id
            )) ?: '';
        }

        $batch_data = [
            'batch_code'     => $batch_code,
            'supplier_id'   => $po->supplier_id ?: null,
            'supplier'       => $supplier_name,
            'import_date'    => current_time('Y-m-d'),
            'shipping_fee'  => (float) $po->shipping_fee,
            'tax_fee'       => (float) $po->tax_fee,
            'service_fee'   => (float) $po->service_fee,
            'inspection_fee'=> (float) $po->inspection_fee,
            'packing_fee'   => (float) $po->packing_fee,
            'other_fee'     => (float) $po->other_fee,
            'notes'         => 'Tạo từ đơn đặt hàng ' . $po->po_code,
            'status'        => 'draft',
        ];

        // Wrap all writes in a transaction: if import_items INSERT fails,
        // the import_batch and PO status update are both rolled back.
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->insert($batch_table, $batch_data);
            $batch_id = $wpdb->insert_id;
            if (! $batch_id) throw new \Exception('Không thể tạo lô nhập kho.');

            $items_table = $wpdb->prefix . 'htw_import_items';
            foreach ($items as $item) {
                $inserted = $wpdb->insert($items_table, [
                    'batch_id'   => $batch_id,
                    'product_id' => $item['product_id'],
                    'qty'        => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'total_cost' => $item['line_total'],
                ]);
                if ($inserted === false) throw new \Exception('Không thể lưu sản phẩm vào lô.');
            }

            // Preserve existing status: keep 'paid_off' if already fully paid, otherwise set 'received'
            $new_status = ($po->status === 'paid_off') ? 'paid_off' : 'received';
            $wpdb->update($wpdb->prefix . 'htw_purchase_orders', [
                'status'          => $new_status,
                'import_batch_id' => $batch_id,
            ], ['id' => $id]);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Tạo lô nhập thất bại: ' . $e->getMessage());
        }

        wp_send_json_success([
            'id'         => $batch_id,
            'batch_code' => $batch_code,
            'message'    => 'Đã tạo lô nhập "' . $batch_code . '" từ đơn đặt hàng. Vui lòng vào trang Nhập kho để xác nhận.',
        ]);
    }

    public static function ajax_record_payment(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id           = absint($_POST['id'] ?? 0);
        $amount       = (float) ($_POST['amount'] ?? 0);
        $payment_date = sanitize_text_field($_POST['payment_date'] ?? current_time('Y-m-d'));
        $note         = sanitize_text_field($_POST['note'] ?? '');

        if ($amount <= 0) {
            wp_send_json_error('Số tiền thanh toán phải lớn hơn 0.');
        }

        $po = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $id
        ));

        if (! $po) {
            wp_send_json_error('Đơn đặt hàng không tồn tại.');
        }
        if ($po->status === 'draft') {
            wp_send_json_error('Không thể thanh toán đơn ở trạng thái nháp.');
        }

        // Guard against overpayment: reject payments exceeding the remaining balance.
        // A 1đ tolerance handles floating-point display rounding.
        $amount_remaining = (float) $po->amount_remaining;
        if ($amount > $amount_remaining + 1) {
            wp_send_json_error(
                'Số tiền thanh toán (' . number_format($amount, 0, ',', '.') . ' đ) vượt quá số còn nợ (' . number_format($amount_remaining, 0, ',', '.') . ' đ). '
                . 'Nếu muốn ghi nhận trả thừa, hãy điều chỉnh số tiền cho khớp số còn lại.'
            );
        }

        $wpdb->insert($wpdb->prefix . 'htw_po_payments', [
            'po_id'        => $id,
            'amount'       => $amount,
            'payment_date' => $payment_date,
            'note'         => $note,
        ]);

        $new_paid      = NumberHelper::computePaidFromPayments($wpdb, $id);
        $new_remaining = max(0, (float) $po->total_amount - $new_paid);
        $new_status    = NumberHelper::isZeroOrNegative(number_format($new_remaining, 2, '.', '')) ? 'paid_off' : $po->status;

        $wpdb->update($wpdb->prefix . 'htw_purchase_orders', [
            'amount_paid'      => $new_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ], ['id' => $id]);

        $msg = 'Đã ghi nhận thanh toán ' . number_format($amount, 0, ',', '.') . ' đ.';
        if ($new_status === 'paid_off') {
            $msg .= ' Đơn đặt hàng đã được thanh toán đủ.';
        } else {
            $msg .= ' Còn nợ: ' . number_format($new_remaining, 0, ',', '.') . ' đ.';
        }

        wp_send_json_success([
            'message'          => $msg,
            'amount_paid'      => $new_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ]);
    }

    public static function ajax_delete(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $id
        ));

        if ('draft' !== $status && 'received' !== $status) {
            wp_send_json_error('Chỉ có thể xóa đơn ở trạng thái nháp hoặc đã nhận hàng.');
        }

        $po = $wpdb->get_row($wpdb->prepare(
            "SELECT import_batch_id FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $id
        ));

        if (! empty($po->import_batch_id)) {
            $batch = $wpdb->get_row($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}htw_import_batches WHERE id = %d", $po->import_batch_id
            ));
            if ($batch && $batch->status === 'confirmed') {
                wp_send_json_error('Không thể xóa: lô nhập kho đã được xác nhận. Vui lòng hoàn tác lô nhập kho trước.');
            }
            // Delete the linked import batch and its items
            $wpdb->delete($wpdb->prefix . 'htw_import_items', ['batch_id' => $po->import_batch_id]);
            $wpdb->delete($wpdb->prefix . 'htw_import_batches', ['id' => $po->import_batch_id]);
        }

        $wpdb->delete($wpdb->prefix . 'htw_po_payments',          ['po_id' => $id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'htw_purchase_order_items', ['po_id' => $id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'htw_purchase_orders',        ['id' => $id],   ['%d']);

        wp_send_json_success('Đã xóa đơn đặt hàng.');
    }

    public static function ajax_edit_payment(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $payment_id = absint($_POST['payment_id'] ?? 0);
        $amount     = (float) ($_POST['amount'] ?? 0);
        $date       = sanitize_text_field($_POST['payment_date'] ?? current_time('Y-m-d'));
        $note       = sanitize_text_field($_POST['note'] ?? '');
        $po_id      = absint($_POST['po_id'] ?? 0);

        if (! $payment_id) {
            wp_send_json_error('ID thanh toán không hợp lệ.');
        }
        if ($amount <= 0) {
            wp_send_json_error('Số tiền thanh toán phải lớn hơn 0.');
        }

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_po_payments WHERE id = %d", $payment_id
        ));
        if (! $payment) {
            wp_send_json_error('Bản ghi thanh toán không tồn tại.');
        }

        $po = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $payment->po_id
        ));

        // Guard against overpayment when editing:
        // remaining after edit = total - (current_paid - old_amount + new_amount)
        //                       = total - current_paid + old_amount - new_amount
        // Simplified: new_remaining = old_remaining + old_payment_amount - new_amount
        // Allow 1đ tolerance for rounding.
        if ($po) {
            $projected_remaining = (float) $po->amount_remaining + (float) $payment->amount - $amount;
            if ($projected_remaining < -1) {
                wp_send_json_error(
                    'Số tiền sửa (' . number_format($amount, 0, ',', '.') . ' đ) vượt quá số còn nợ. '
                    . 'Số còn lại sau sửa sẽ âm (' . number_format($projected_remaining, 0, ',', '.') . ' đ).'
                );
            }
        }

        $diff = $amount - (float) $payment->amount;

        $wpdb->update($wpdb->prefix . 'htw_po_payments', [
            'amount'       => $amount,
            'payment_date' => $date,
            'note'         => $note,
        ], ['id' => $payment_id]);

        // Recalculate PO totals from SUM of payments — authoritative source
        $new_paid      = NumberHelper::computePaidFromPayments($wpdb, $payment->po_id);
        $new_remaining = max(0, (float) $po->total_amount - $new_paid);
        $new_status    = NumberHelper::isZeroOrNegative(number_format($new_remaining, 2, '.', '')) ? 'paid_off' : $po->status;

        $wpdb->update($wpdb->prefix . 'htw_purchase_orders', [
            'amount_paid'      => $new_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ], ['id' => $payment->po_id]);

        wp_send_json_success([
            'message'          => 'Đã cập nhật thanh toán.',
            'amount_paid'      => $new_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ]);
    }

    public static function ajax_delete_payment(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $payment_id = absint($_POST['payment_id'] ?? 0);
        if (! $payment_id) {
            wp_send_json_error('ID thanh toán không hợp lệ.');
        }

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_po_payments WHERE id = %d", $payment_id
        ));
        if (! $payment) {
            wp_send_json_error('Bản ghi thanh toán không tồn tại.');
        }

        $po = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}htw_purchase_orders WHERE id = %d", $payment->po_id
        ));

        $wpdb->delete($wpdb->prefix . 'htw_po_payments', ['id' => $payment_id]);

        // Recalculate from SUM of payments — authoritative source
        $new_paid      = NumberHelper::computePaidFromPayments($wpdb, $payment->po_id);
        $new_remaining = max(0, (float) $po->total_amount - $new_paid);

        // Status regression: determine correct status based on remaining balance.
        // - If payment deletion causes remaining > 0:
        //   * paid_off  → revert to pre-paid_off status:
        //       • received, if the linked import batch is confirmed
        //       • confirmed, otherwise (goods not yet received)
        // - Any other current status: leave unchanged.
        if ($po->status === 'paid_off' && $new_remaining > 0.01) {
            // Determine whether goods have been received by checking import_batch_id
            $has_received_batch = ! empty($po->import_batch_id) && $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}htw_import_batches WHERE id = %d AND status = 'confirmed'",
                $po->import_batch_id
            ));
            $new_status = $has_received_batch ? 'received' : 'confirmed';
        } else {
            $new_status = $po->status;
        }

        $wpdb->update($wpdb->prefix . 'htw_purchase_orders', [
            'amount_paid'      => $new_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ], ['id' => $payment->po_id]);

        wp_send_json_success([
            'message'          => 'Đã xóa thanh toán.',
            'amount_paid'      => $new_paid,
            'amount_remaining' => $new_remaining,
            'status'           => $new_status,
        ]);
    }
}
