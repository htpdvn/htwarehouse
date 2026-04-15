<?php

namespace HTWarehouse\Pages;

defined('ABSPATH') || exit;

class ActivityLogPage
{
    // ── Render trang admin ─────────────────────────────────────────────────────
    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Bạn không có quyền truy cập trang này.');
        }

        $template = HTW_PLUGIN_DIR . 'templates/activity-log/log.php';
        if (file_exists($template)) {
            include $template;
        }
    }

    // ── AJAX: Lấy dữ liệu log (JSON) ──────────────────────────────────────────
    public static function ajax_get_logs(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $table = $wpdb->prefix . 'htw_activity_logs';

        $page     = max(1, absint($_POST['page'] ?? 1));
        $per_page = 50;
        $offset   = ($page - 1) * $per_page;

        // ── Filters ──────────────────────────────────────────────────────────
        $where    = '1=1';
        $params   = [];

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to']   ?? '');
        $obj_type  = sanitize_text_field($_POST['object_type'] ?? '');
        $log_action = sanitize_text_field($_POST['log_action'] ?? '');  // ← NOT $_POST['action'] (that's the WP AJAX action name)
        $user_id   = absint($_POST['user_id'] ?? 0);
        $keyword   = sanitize_text_field($_POST['keyword']   ?? '');

        if ($date_from) {
            $where   .= ' AND created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where   .= ' AND created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }
        if ($obj_type) {
            $where   .= ' AND object_type = %s';
            $params[] = $obj_type;
        }
        if ($log_action) {
            $where   .= ' AND action = %s';
            $params[] = $log_action;
        }
        if ($user_id > 0) {
            $where   .= ' AND user_id = %d';
            $params[] = $user_id;
        }
        if ($keyword) {
            $where   .= ' AND (object_code LIKE %s OR summary LIKE %s OR user_login LIKE %s)';
            $like     = '%' . $wpdb->esc_like($keyword) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // ── Count ────────────────────────────────────────────────────────────
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total     = (int) (empty($params)
            ? $wpdb->get_var($count_sql)
            : $wpdb->get_var($wpdb->prepare($count_sql, $params))
        );

        // ── Data ─────────────────────────────────────────────────────────────
        $params_with_limit   = $params;
        $params_with_limit[] = $per_page;
        $params_with_limit[] = $offset;

        $data_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $rows     = empty($params)
            ? $wpdb->get_results($wpdb->prepare($data_sql, [$per_page, $offset]), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($data_sql, $params_with_limit), ARRAY_A);

        wp_send_json_success([
            'rows'       => $rows ?: [],
            'total'      => $total,
            'per_page'   => $per_page,
            'page'       => $page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    // ── AJAX: Xuất CSV ─────────────────────────────────────────────────────────
    public static function ajax_export_csv(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            http_response_code(403);
            die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htw_activity_logs';

        // ── Build same filter as ajax_get_logs ────────────────────────────────
        $where  = '1=1';
        $params = [];

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to']   ?? '');
        $obj_type  = sanitize_text_field($_POST['object_type'] ?? '');
        $log_action = sanitize_text_field($_POST['log_action'] ?? '');  // S7 fix: renamed from 'action' to avoid collision with WordPress AJAX hook name
        $user_id   = absint($_POST['user_id'] ?? 0);
        $keyword   = sanitize_text_field($_POST['keyword']   ?? '');

        if ($date_from) { $where .= ' AND created_at >= %s'; $params[] = $date_from . ' 00:00:00'; }
        if ($date_to)   { $where .= ' AND created_at <= %s'; $params[] = $date_to   . ' 23:59:59'; }
        if ($obj_type)  { $where .= ' AND object_type = %s'; $params[] = $obj_type; }
        if ($log_action)  { $where .= ' AND action = %s';       $params[] = $log_action; }
        if ($user_id > 0) { $where .= ' AND user_id = %d';   $params[] = $user_id; }
        if ($keyword) {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $where .= ' AND (object_code LIKE %s OR summary LIKE %s OR user_login LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 10000";
        $rows = empty($params)
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        // ── Output CSV ────────────────────────────────────────────────────────
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="htw-activity-log-' . date('Ymd-His') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        // BOM cho Excel UTF-8
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, ['ID', 'Thời gian', 'Người dùng', 'Hành động', 'Loại đối tượng', 'ID đối tượng', 'Mã', 'Mô tả', 'IP']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['created_at'],
                $row['user_login'],
                $row['action'],
                $row['object_type'],
                $row['object_id'],
                $row['object_code'],
                $row['summary'],
                $row['ip_address'],
            ]);
        }

        fclose($out);
        exit;
    }
}
