<?php

namespace HTWarehouse\Pages;

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
            wp_send_json_success(['message' => 'Đã cập nhật sản phẩm.']);
        } else {
            // Check SKU uniqueness
            if (! empty($sku)) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE sku = %s", $sku));
                if ($exists) {
                    wp_send_json_error('SKU đã tồn tại.');
                }
            }
            $data['current_stock'] = '0';
            $data['avg_cost']      = '0';
            $fmt[]                 = '%s';
            $fmt[]                 = '%s';
            $wpdb->insert($table, $data, $fmt);
            wp_send_json_success(['id' => $wpdb->insert_id, 'message' => 'Đã thêm sản phẩm.']);
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

        $wpdb->delete($wpdb->prefix . 'htw_products', ['id' => $id], ['%d']);
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
            "SELECT category, name, sku, unit, barcode, current_stock, avg_cost, product_url, notes
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
        $header_validated = false;

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
            $url_idx     = $header_map['link sản phẩm'] ?? $header_map['product_url'] ?? null;
            $notes_idx   = $header_map['ghi chú']       ?? $header_map['notes']       ?? null;
            // (Tồn kho / Giá vốn columns are intentionally never read)

            $category = sanitize_text_field($cols[ $cat_idx ] ?? '');
            $name     = sanitize_text_field($cols[ $name_idx ] ?? '');
            $sku      = sanitize_text_field($cols[ $sku_idx ] ?? '');

            // Skip entirely blank rows silently
            if ($category === '' && $name === '' && $sku === '') {
                $skipped++;
                continue;
            }

            // Per-row required field validation
            if (empty($category)) {
                $errors[] = "Dòng {$row_num}: Thiếu Danh mục.";
                continue;
            }
            if (empty($name)) {
                $errors[] = "Dòng {$row_num}: Thiếu Tên sản phẩm.";
                continue;
            }
            if (empty($sku)) {
                $errors[] = "Dòng {$row_num}: Thiếu SKU (bắt buộc để nhận diện sản phẩm).";
                continue;
            }

            // Optional fields
            $unit     = $unit_idx    !== null ? (sanitize_text_field($cols[ $unit_idx ]      ?? '') ?: 'cái') : 'cái';
            $barcode  = $barcode_idx !== null ? sanitize_text_field($cols[ $barcode_idx ]    ?? '') : '';
            $prod_url = $url_idx     !== null ? esc_url_raw($cols[ $url_idx ]                ?? '') : '';
            $notes    = $notes_idx   !== null ? sanitize_textarea_field($cols[ $notes_idx ]  ?? '') : '';

            // Upsert: update if SKU exists, insert if new
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE sku = %s",
                $sku
            ));

            if ($existing_id) {
                // Update non-financial fields only — never touch current_stock or avg_cost
                $wpdb->update(
                    $table,
                    [
                        'category'    => $category,
                        'name'        => $name,
                        'unit'        => $unit,
                        'barcode'     => $barcode,
                        'product_url' => $prod_url,
                        'notes'       => $notes,
                    ],
                    ['id' => $existing_id],
                    ['%s', '%s', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
            } else {
                // Insert new — current_stock and avg_cost always start at 0
                $wpdb->insert(
                    $table,
                    [
                        'sku'           => $sku,
                        'name'          => $name,
                        'category'      => $category,
                        'unit'          => $unit,
                        'barcode'       => $barcode,
                        'product_url'   => $prod_url,
                        'notes'         => $notes,
                        'current_stock' => '0',
                        'avg_cost'      => '0',
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
                );
            }
            $imported++;
        }


        fclose($handle);

        $msg = "Đã xử lý {$imported} mục thành công.";
        if ($skipped > 0) {
            $msg .= " Bỏ qua {$skipped} dòng trống.";
        }
        if (! empty($errors)) {
            $msg .= ' Lỗi: ' . implode('; ', array_slice($errors, 0, 5));
        }

        wp_send_json_success(['message' => $msg, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }
}
