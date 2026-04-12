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
            $data['current_stock'] = 0;
            $data['avg_cost']      = 0;
            $fmt[]                 = '%f';
            $fmt[]                 = '%f';
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
}
