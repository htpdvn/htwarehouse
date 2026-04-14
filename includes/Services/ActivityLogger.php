<?php

namespace HTWarehouse\Services;

defined('ABSPATH') || exit;

/**
 * ActivityLogger — Ghi nhật ký thao tác ảnh hưởng đến cơ sở dữ liệu.
 *
 * Cách dùng cơ bản:
 *   ActivityLogger::log('confirm', 'import_batch', $id, $batch_code, 'Xác nhận lô nhập IMP-ABC123');
 *
 * Cách dùng với snapshot tài chính (dành cho confirm / payment):
 *   ActivityLogger::log('confirm', 'export_order', $id, $code, $summary,
 *       before: ['total_revenue' => 1200000, 'stock' => ['P1' => 50]],
 *       after:  ['total_revenue' => 900000,  'stock' => ['P1' => 47]],
 *   );
 */
class ActivityLogger
{
    /**
     * Ghi một entry vào bảng htw_activity_logs.
     *
     * @param string     $action       Loại hành động: create|update|delete|confirm|send_to_import|...
     * @param string     $object_type  Loại đối tượng: import_batch|export_order|product|purchase_order|return_order|supplier|po_payment
     * @param int        $object_id    ID của đối tượng bị tác động.
     * @param string     $object_code  Mã dễ đọc (batch_code, order_code, tên SP, ...). Để '' nếu không có.
     * @param string     $summary      Mô tả ngắn bằng tiếng Việt, dễ đọc cho admin.
     * @param array|null $before       Snapshot trạng thái TRƯỚC khi thao tác (số liệu tài chính, tồn kho...).
     *                                 Sẽ được lưu dưới dạng JSON. null = không ghi.
     * @param array|null $after        Snapshot trạng thái SAU khi thao tác. null = không ghi.
     */
    public static function log(
        string $action,
        string $object_type,
        int $object_id,
        string $object_code = '',
        string $summary = '',
        ?array $before = null,
        ?array $after = null
    ): void {
        global $wpdb;

        $user = wp_get_current_user();

        // Lấy IP thực, xử lý qua proxy
        $ip = self::get_client_ip();

        $wpdb->insert(
            $wpdb->prefix . 'htw_activity_logs',
            [
                'user_id'     => $user->ID,
                'user_login'  => $user->user_login ?: 'system',
                'action'      => $action,
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'object_code' => $object_code,
                'summary'     => $summary,
                'before_data' => $before !== null ? wp_json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'after_data'  => $after  !== null ? wp_json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $ip,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        // Không throw nếu insert thất bại — log không được làm gián đoạn nghiệp vụ chính.
    }

    /**
     * Lấy IP client thực, hỗ trợ proxy / load balancer phổ biến.
     */
    private static function get_client_ip(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (! empty($_SERVER[$header])) {
                // X-Forwarded-For có thể là danh sách IP cách nhau bằng dấu phẩy
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }
}
