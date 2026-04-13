<?php

namespace HTWarehouse\Pages;

use HTWarehouse\Snapshot\SnapshotService;
use HTWarehouse\Snapshot\SnapshotScheduler;

defined('ABSPATH') || exit;

class SnapshotPage
{

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $snapshots = SnapshotService::list();
        $status    = SnapshotScheduler::get_last_status();
        $next_run  = SnapshotScheduler::get_instance()->next_run();

        include HTW_PLUGIN_DIR . 'templates/snapshots/list.php';
    }

    /**
     * AJAX: Create a new snapshot manually.
     */
    public static function ajax_create(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        try {
            $result = SnapshotService::create();
            $result['size_formatted'] = SnapshotService::format_size($result['size_bytes']);
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error('Tạo snapshot thất bại: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Restore a snapshot.
     */
    public static function ajax_restore(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        if (empty($filename)) {
            wp_send_json_error('Thiếu tên file snapshot.');
        }

        try {
            $result = SnapshotService::restore($filename);

            $msg  = 'Khôi phục thành công từ snapshot: ' . $filename . '. ';
            $msg .= 'Đã tạo backup khẩn cấp: ' . ($result['emergency_backup']['filename'] ?? 'n/a') . '. ';
            $msg .= 'Bạn có thể dùng backup khẩn cấp này để rollback ngược nếu cần.';

            wp_send_json_success([
                'message'         => $msg,
                'restored_counts' => $result['restored_counts'],
                'emergency_backup' => $result['emergency_backup']['filename'] ?? '',
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error('Khôi phục thất bại: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Delete a snapshot.
     */
    public static function ajax_delete(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        if (empty($filename)) {
            wp_send_json_error('Thiếu tên file snapshot.');
        }

        try {
            SnapshotService::delete($filename);
            wp_send_json_success('Đã xoá snapshot: ' . $filename);
        } catch (\Throwable $e) {
            wp_send_json_error('Xoá snapshot thất bại: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Get scheduler status.
     */
    public static function ajax_get_status(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $status   = SnapshotScheduler::get_last_status();
        $next_run = SnapshotScheduler::get_instance()->next_run();

        wp_send_json_success([
            'is_scheduled' => SnapshotScheduler::get_instance()->is_scheduled(),
            'next_run'      => $next_run ? date_i18n('d/m/Y H:i', $next_run) : null,
            'last_status'  => $status,
        ]);
    }
}
