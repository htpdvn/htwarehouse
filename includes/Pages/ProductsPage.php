<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Services\ActivityLogger;

defined('ABSPATH') || exit;

class ProductsPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        include HTW_PLUGIN_DIR . 'templates/products/list.php';
    }

    // ── AJAX: Save (create or update) ─────────────────────────────────────────
    public static function ajax_save(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htw_products';

        $id          = absint($_POST['id'] ?? 0);
        $sku         = sanitize_text_field($_POST['sku'] ?? '');
        $name        = sanitize_text_field($_POST['name'] ?? '');
        $category    = sanitize_text_field($_POST['category'] ?? '');
        $unit        = sanitize_text_field($_POST['unit'] ?? 'cái');
        $barcode     = sanitize_text_field($_POST['barcode'] ?? '');
        $notes       = sanitize_textarea_field($_POST['notes'] ?? '');
        $product_url = esc_url_raw($_POST['product_url'] ?? '');

        // Image: prefer uploaded attachment ID, fall back to URL
        $image_url = '';
        if (! empty($_POST['image_attachment_id'])) {
            $att_id    = absint($_POST['image_attachment_id']);
            $image_url = wp_get_attachment_url($att_id) ?: '';
        }
        if (empty($image_url)) {
            $image_url = esc_url_raw($_POST['image_url'] ?? '');
        }

        if (empty($name)) {
            wp_send_json_error('Tên sản phẩm không được để trống.');
        }

        $data = compact('sku', 'name', 'category', 'unit', 'barcode', 'image_url', 'notes', 'product_url');
        $fmt  = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($id > 0) {
            // Check SKU uniqueness — exclude current product from check
            if (! empty($sku)) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE sku = %s AND id != %d",
                    $sku,
                    $id
                ));
                if ($exists) {
                    wp_send_json_error('SKU "' . $sku . '" đã được sử dụng bởi sản phẩm khác.');
                }
            }
            $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
            ActivityLogger::log('update', 'product', $id, $sku ?: $name, 'Cập nhật sản phẩm: ' . $name . ($sku ? ' [SKU: ' . $sku . ']' : ''));
            wp_send_json_success(['message' => 'Đã cập nhật sản phẩm.']);
        } else {
            // Check SKU uniqueness (only when user provides one).
            // If sku is empty, generate one and retry on Duplicate Entry error.
            if (! empty($sku)) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE sku = %s", $sku));
                if ($exists) {
                    wp_send_json_error('SKU đã tồn tại.');
                }
            } else {
                // Auto-generate SKU: INSERT and retry on UNIQUE constraint error.
                // Unlike the PO/order batch codes, here we only generate when the
                // field is intentionally left blank — so no user-facing retry UX needed.
                $attempts = 0;
                $ok = false;
                do {
                    $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
                    $ok  = $wpdb->insert($table, ['sku' => $sku]) !== false;
                    if (! $ok && stripos($wpdb->last_error, 'duplicate') === false) {
                        wp_send_json_error('Lỗi tạo SKU: ' . $wpdb->last_error);
                    }
                    $attempts++;
                } while (! $ok && $attempts < 10);
                if (! $ok) {
                    wp_send_json_error('Không thể tạo SKU không trùng lặp. Vui lòng nhập SKU thủ công.');
                }
                // Dummy row removed; real insert below rebuilds $data with the real sku
                $wpdb->query("DELETE FROM {$table} WHERE id = {$wpdb->insert_id}");
                $data['sku'] = $sku;
                $fmt = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];
            }
            $data['current_stock'] = '0';
            $data['avg_cost']      = '0';
            $fmt[]                 = '%s';
            $fmt[]                 = '%s';
            $wpdb->insert($table, $data, $fmt);
            $new_id = $wpdb->insert_id;
            ActivityLogger::log('create', 'product', $new_id, $sku ?: $name, 'Tạo mới sản phẩm: ' . $name . ($sku ? ' [SKU: ' . $sku . ']' : ''));
            wp_send_json_success(['id' => $new_id, 'message' => 'Đã thêm sản phẩm.']);
        }
    }

    // ── AJAX: Delete ──────────────────────────────────────────────────────────
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

        // Prevent delete if stock is non-zero
        $stock = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT current_stock FROM {$wpdb->prefix}htw_products WHERE id = %d",
            $id
        ));
        if ($stock > 0) {
            wp_send_json_error('Không thể xoá sản phẩm còn tồn kho. Vui lòng xuất hết hàng trước.');
        }

        // Lấy thông tin sản phẩm trước khi xóa
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT sku, name FROM {$wpdb->prefix}htw_products WHERE id = %d", $id
        ), ARRAY_A);

        $wpdb->delete($wpdb->prefix . 'htw_products', ['id' => $id], ['%d']);

        ActivityLogger::log(
            'delete', 'product', $id,
            $product['sku'] ?? '',
            'Xóa sản phẩm: ' . ($product['name'] ?? 'ID=' . $id) . ($product['sku'] ? ' [SKU: ' . $product['sku'] . ']' : '')
        );

        wp_send_json_success('Đã xoá sản phẩm.');
    }

    // ── AJAX: Get distinct categories ─────────────────────────────────────────
    public static function ajax_get_categories(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $cats = $wpdb->get_col(
            "SELECT DISTINCT category FROM {$wpdb->prefix}htw_products
             WHERE category IS NOT NULL AND category != ''
             ORDER BY category ASC"
        );
        wp_send_json_success($cats);
    }

    // ── AJAX: Export categories as CSV ────────────────────────────────────────
    public static function ajax_export_categories(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htw_products';

        // Fetch ALL products (ordered by category then name for easy reading)
        $rows = $wpdb->get_results(
            "SELECT category, name, sku, unit, barcode, current_stock, avg_cost, image_url, product_url, notes
             FROM {$table}
             ORDER BY category ASC, name ASC",
            ARRAY_A
        );

        // Clean output buffer
        if (ob_get_length()) {
            ob_end_clean();
        }

        $filename = 'danh-muc-san-pham-' . gmdate('Ymd-His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        $out = fopen('php://output', 'w');

        // UTF-8 BOM — ensures Excel opens the file with correct encoding
        fwrite($out, "\xEF\xBB\xBF");

        // Header row — matches the import column names exactly
        fputcsv($out, [
            'Danh mục',
            'Tên sản phẩm',
            'SKU',
            'Đơn vị',
            'Barcode',
            'Tồn kho',
            'Giá vốn',
            'Link ảnh',
            'Link sản phẩm',
            'Ghi chú',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['category'],
                $row['name'],
                $row['sku'],
                $row['unit'],
                $row['barcode'],
                $row['current_stock'],
                $row['avg_cost'],
                $row['image_url'],
                $row['product_url'],
                $row['notes'],
            ]);
        }

        fclose($out);
        exit;
    }


    // ── AJAX: Import categories from CSV ──────────────────────────────────────
    public static function ajax_import_categories(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            wp_send_json_error('Không tìm thấy file CSV.');
        }

        $file = $_FILES['csv_file']['tmp_name'];

        // Open with auto-detection of line endings
        ini_set('auto_detect_line_endings', '1');
        $handle = fopen($file, 'r');
        if (! $handle) {
            wp_send_json_error('Không thể đọc file CSV.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htw_products';

        $imported   = 0;
        $skipped    = 0;
        $errors     = [];
        $row_num    = 0;
        $header_map = [];

        // ── Step 1: Parse all rows first (before any DB write) ─────────────────
        // Collect upsert data to allow a single transaction for all writes.
        $upserts   = [];  // [sku => ['existing_id' => int|null, 'data' => [...]]]
        $seen_skus = [];  // track duplicate SKUs within the file itself

        while (($cols = fgetcsv($handle)) !== false) {
            // Strip UTF-8 BOM from very first cell
            if ($row_num === 0) {
                $cols[0] = ltrim($cols[0], "\xEF\xBB\xBF");
            }

            // Normalize: trim all cells
            $cols = array_map('trim', $cols);

            // ── Row 0: parse header ───────────────────────────────────────────
            if ($row_num === 0) {
                foreach ($cols as $idx => $cell) {
                    $header_map[ mb_strtolower($cell) ] = $idx;
                }

                // Validate required columns exist in the header
                $has_sku  = isset($header_map['sku']);
                $has_name = isset($header_map['tên sản phẩm']) || isset($header_map['name']);
                $has_cat  = isset($header_map['danh mục']) || isset($header_map['category']);

                if (! $has_sku || ! $has_name || ! $has_cat) {
                    fclose($handle);
                    $missing = [];
                    if (! $has_cat)  $missing[] = '"Danh mục"';
                    if (! $has_name) $missing[] = '"Tên sản phẩm"';
                    if (! $has_sku)  $missing[] = '"SKU"';
                    wp_send_json_error('File thiếu cột bắt buộc: ' . implode(', ', $missing) . '. Vui lòng kiểm tra file và thử lại.');
                }

                $row_num++;
                continue;
            }

            $row_num++;

            // Resolve column indices (header already validated — these keys exist)
            $cat_idx     = $header_map['danh mục']     ?? $header_map['category'];
            $name_idx    = $header_map['tên sản phẩm'] ?? $header_map['name'];
            $sku_idx     = $header_map['sku'];
            // Optional columns
            $unit_idx    = $header_map['đơn vị']        ?? $header_map['unit']        ?? null;
            $barcode_idx = $header_map['barcode']       ?? null;
            $img_idx     = $header_map['link ảnh']      ?? $header_map['image_url']   ?? null;
            $url_idx     = $header_map['link sản phẩm'] ?? $header_map['product_url'] ?? null;
            $notes_idx   = $header_map['ghi chú']       ?? $header_map['notes']       ?? null;

            $category = sanitize_text_field($cols[ $cat_idx ] ?? '');
            $name     = sanitize_text_field($cols[ $name_idx ] ?? '');
            $sku      = sanitize_text_field($cols[ $sku_idx ] ?? '');

            // Skip entirely blank rows silently
            if ($category === '' && $name === '' && $sku === '') {
                $skipped++;
                continue;
            }

            // Per-row required field validation (collect errors, don't abort yet)
            $row_ok = true;
            if (empty($category)) {
                $errors[] = "Dòng {$row_num}: Thiếu Danh mục.";
                $row_ok = false;
            }
            if (empty($name)) {
                $errors[] = "Dòng {$row_num}: Thiếu Tên sản phẩm.";
                $row_ok = false;
            }
            if (empty($sku)) {
                $errors[] = "Dòng {$row_num}: Thiếu SKU (bắt buộc để nhận diện sản phẩm).";
                $row_ok = false;
            }

            // Detect duplicate SKUs within the file itself
            if ($sku !== '' && isset($seen_skus[$sku]) && $row_ok) {
                $errors[] = "Dòng {$row_num}: SKU \"{$sku}\" bị trùng lặp trong file (trước đó ở dòng {$seen_skus[$sku]}).";
                $row_ok = false;
            }

            if (! $row_ok) {
                continue;
            }

            $seen_skus[$sku] = $row_num;

            // Optional fields
            $unit     = $unit_idx    !== null ? (sanitize_text_field($cols[ $unit_idx ]      ?? '') ?: 'cái') : 'cái';
            $barcode  = $barcode_idx !== null ? sanitize_text_field($cols[ $barcode_idx ]    ?? '') : '';
            $img_url  = $img_idx     !== null ? esc_url_raw($cols[ $img_idx ]                ?? '') : '';
            $prod_url = $url_idx     !== null ? esc_url_raw($cols[ $url_idx ]                ?? '') : '';
            $notes    = $notes_idx   !== null ? sanitize_textarea_field($cols[ $notes_idx ]  ?? '') : '';

            $upserts[$sku] = [
                'category'  => $category,
                'name'     => $name,
                'unit'     => $unit,
                'barcode'  => $barcode,
                'img_url'  => $img_url,
                'prod_url' => $prod_url,
                'notes'    => $notes,
            ];
        }

        fclose($handle);

        // No valid rows at all — return early
        if (empty($upserts)) {
            $msg = "Không có dòng hợp lệ để xử lý.";
            if ($skipped > 0) {
                $msg .= " Bỏ qua {$skipped} dòng trống.";
            }
            if (! empty($errors)) {
                $msg .= ' Lỗi: ' . implode('; ', array_slice($errors, 0, 5));
            }
            wp_send_json_success(['message' => $msg, 'imported' => 0, 'skipped' => $skipped, 'errors' => $errors]);
        }

        // ── Step 2: Resolve existing SKUs in ONE query ──────────────────────────
        // Batch lookup — O(1) instead of N individual queries.
        $sku_list = array_keys($upserts);
        $placeholders = implode(',', array_fill(0, count($sku_list), '%s'));
        $existing_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sku FROM {$table} WHERE sku IN ({$placeholders})",
            ...$sku_list
        ), ARRAY_A);
        foreach ($existing_rows as $row) {
            $upserts[ $row['sku'] ]['existing_id'] = (int) $row['id'];
        }

        // ── Step 3: Wrap all writes in a single transaction ──────────────────────
        // If any row fails, the entire import is rolled back — no partial state.
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($upserts as $sku => $row) {
                if (! empty($row['existing_id'])) {
                    // Update non-financial fields only — never touch current_stock or avg_cost
                    $updated = $wpdb->update(
                        $table,
                        [
                            'category'    => $row['category'],
                            'name'        => $row['name'],
                            'unit'        => $row['unit'],
                            'barcode'     => $row['barcode'],
                            'image_url'   => $row['img_url'],
                            'product_url' => $row['prod_url'],
                            'notes'       => $row['notes'],
                        ],
                        ['id' => $row['existing_id']],
                        ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                    if ($updated === false) {
                        throw new \Exception("Dòng SKU \"{$sku}\": lỗi cập nhật — {$wpdb->last_error}");
                    }
                } else {
                    // Insert new — current_stock and avg_cost always start at 0
                    $inserted = $wpdb->insert(
                        $table,
                        [
                            'sku'           => $sku,
                            'name'          => $row['name'],
                            'category'      => $row['category'],
                            'unit'          => $row['unit'],
                            'barcode'       => $row['barcode'],
                            'image_url'     => $row['img_url'],
                            'product_url'   => $row['prod_url'],
                            'notes'         => $row['notes'],
                            'current_stock' => '0',
                            'avg_cost'      => '0',
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
                    );
                    if ($inserted === false) {
                        throw new \Exception("Dòng SKU \"{$sku}\": lỗi chèn mới — {$wpdb->last_error}");
                    }
                }
                $imported++;
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Import CSV thất bại (đã rollback): ' . $e->getMessage());
        }

        $msg = "Đã xử lý {$imported} mục thành công.";
        if ($skipped > 0) {
            $msg .= " Bỏ qua {$skipped} dòng trống.";
        }
        if (! empty($errors)) {
            $msg .= ' Lỗi: ' . implode('; ', array_slice($errors, 0, 5));
        }

        ActivityLogger::log(
            'import_csv',
            'product',
            0,
            '',
            "Import CSV sản phẩm: {$imported} mục thành công" . ($skipped > 0 ? ", {$skipped} bỏ qua" : '') . (! empty($errors) ? ', ' . count($errors) . ' lỗi' : '')
        );

        wp_send_json_success(['message' => $msg, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }
}
