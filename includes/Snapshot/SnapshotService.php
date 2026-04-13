<?php

namespace HTWarehouse\Snapshot;

defined('ABSPATH') || exit;

class SnapshotService
{

    const SNAPSHOT_VERSION   = '1.0';
    const KEEP_DAYS_DEFAULT = 7;

    /**
     * List of all plugin tables to snapshot (parent tables before child tables).
     * Order matters for restore: child tables (items/payments) must be restored
     * before parent tables so that FK constraints are satisfied during import.
     */
    private static function get_tables(): array
    {
        global $wpdb;
        return [
            // Child tables (must be restored first to satisfy FK)
            $wpdb->prefix . 'htw_po_payments',
            $wpdb->prefix . 'htw_purchase_order_items',
            $wpdb->prefix . 'htw_export_items',
            $wpdb->prefix . 'htw_import_items',
            // Parent tables
            $wpdb->prefix . 'htw_purchase_orders',
            $wpdb->prefix . 'htw_export_orders',
            $wpdb->prefix . 'htw_import_batches',
            $wpdb->prefix . 'htw_products',
            $wpdb->prefix . 'htw_suppliers',
        ];
    }

    /**
     * Short table names (without wp prefix) used as keys in snapshot JSON.
     */
    private static function get_table_keys(): array
    {
        global $wpdb;
        return [
            $wpdb->prefix . 'htw_po_payments'          => 'htw_po_payments',
            $wpdb->prefix . 'htw_purchase_order_items' => 'htw_purchase_order_items',
            $wpdb->prefix . 'htw_export_items'         => 'htw_export_items',
            $wpdb->prefix . 'htw_import_items'         => 'htw_import_items',
            $wpdb->prefix . 'htw_purchase_orders'      => 'htw_purchase_orders',
            $wpdb->prefix . 'htw_export_orders'         => 'htw_export_orders',
            $wpdb->prefix . 'htw_import_batches'        => 'htw_import_batches',
            $wpdb->prefix . 'htw_products'              => 'htw_products',
            $wpdb->prefix . 'htw_suppliers'             => 'htw_suppliers',
        ];
    }

    /**
     * Get the snapshot directory path, creating it if it doesn't exist.
     */
    private static function get_snapshot_dir(): string
    {
        $upload = wp_upload_dir();
        $dir    = $upload['basedir'] . '/htw-snapshots';

        if (! file_exists($dir)) {
            wp_mkdir_p($dir);

            // Protect directory with an index.php
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
        }

        return $dir;
    }

    /**
     * Get the snapshot directory URL.
     */
    private static function get_snapshot_url(): string
    {
        $upload = wp_upload_dir();
        return $upload['baseurl'] . '/htw-snapshots';
    }

    /**
     * Create a new snapshot.
     *
     * @return array{filename: string, path: string, url: string, created_at: string, row_counts: array}
     */
    public static function create(): array
    {
        global $wpdb;

        $created_at = current_time('mysql');
        $date_str   = date('Y-m-d_His', current_time('timestamp'));
        $filename   = "snapshot-{$date_str}.json";
        $dir        = self::get_snapshot_dir();
        $path       = $dir . '/' . $filename;

        // Dump all tables
        $tables_data = [];
        $row_counts  = [];
        $table_keys  = self::get_table_keys();

        foreach (self::get_tables() as $table) {
            $short_key           = $table_keys[ $table ];
            $tables_data[ $short_key ] = $wpdb->get_results(
                "SELECT * FROM {$table}",
                ARRAY_A
            ) ?: [];
            $row_counts[ $short_key ]  = count($tables_data[ $short_key ]);
        }

        $snapshot = [
            'version'        => self::SNAPSHOT_VERSION,
            'created_at'    => $created_at,
            'plugin_version' => defined('HTW_VERSION') ? HTW_VERSION : 'unknown',
            'wp_version'    => get_bloginfo('version'),
            'tables'        => $tables_data,
            'row_counts'    => $row_counts,
        ];

        $json = wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (false === $json) {
            throw new \Exception('Không thể mã hoá dữ liệu snapshot thành JSON.');
        }

        $written = file_put_contents($path, $json);

        if (false === $written) {
            throw new \Exception('Không thể ghi file snapshot: ' . $path);
        }

        return [
            'filename'   => $filename,
            'path'       => $path,
            'url'        => self::get_snapshot_url() . '/' . $filename,
            'created_at' => $created_at,
            'row_counts' => $row_counts,
            'size_bytes' => $written,
        ];
    }

    /**
     * List all available snapshots.
     *
     * @return array Sorted by date descending (newest first).
     */
    public static function list(): array
    {
        $dir  = self::get_snapshot_dir();
        $list = [];

        $files = glob($dir . '/snapshot-*.json');

        if (false === $files) {
            return [];
        }

        foreach ($files as $file) {
            $filename = basename($file);

            // Parse date from filename: snapshot-YYYY-MM-DD_HHiis.json
            if (! preg_match('/^snapshot-(\d{4}-\d{2}-\d{2})_(\d{6})\.json$/', $filename, $m)) {
                continue;
            }

            $date_str = $m[1] . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);

            // Read file for metadata (size, row_counts)
            $size_bytes = @filesize($file);
            $row_counts = [];

            $content = @file_get_contents($file);
            if ($content) {
                $decoded = json_decode($content, true);
                if (isset($decoded['row_counts'])) {
                    $row_counts = $decoded['row_counts'];
                }
            }

            $list[] = [
                'filename'    => $filename,
                'path'        => $file,
                'url'         => self::get_snapshot_url() . '/' . $filename,
                'created_at'  => $date_str,
                'size_bytes'  => $size_bytes ?: 0,
                'row_counts'  => $row_counts,
            ];
        }

        // Sort descending by created_at
        usort($list, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $list;
    }

    /**
     * Restore a snapshot from file.
     * Creates an emergency backup of current state before restoring.
     *
     * @param string $filename Snapshot filename.
     * @return array{restored_counts: array, emergency_backup: array}
     */
    public static function restore(string $filename): array
    {
        global $wpdb;

        $dir     = self::get_snapshot_dir();
        $filepath = $dir . '/' . basename($filename); // basename to prevent path traversal

        if (! file_exists($filepath)) {
            throw new \Exception('File snapshot không tồn tại: ' . $filename);
        }

        $content = @file_get_contents($filepath);
        if (! $content) {
            throw new \Exception('Không thể đọc file snapshot.');
        }

        $snapshot = json_decode($content, true);
        if (! $snapshot || ! isset($snapshot['tables'])) {
            throw new \Exception('File snapshot không hợp lệ hoặc bị hỏng.');
        }

        // Step 1: Create emergency backup of current state
        $emergency = self::create();
        // Rename emergency backup so it doesn't clutter the list
        $emergency_name = str_replace('.json', '-EMERGENCY.json', basename($emergency['filename']));
        rename($emergency['path'], $dir . '/' . $emergency_name);
        $emergency['filename'] = $emergency_name;

        // Step 2: Begin restore transaction
        $wpdb->query('START TRANSACTION');

        try {
            $restored_counts = [];
            $table_keys      = self::get_table_keys();
            $reverse_keys    = array_flip($table_keys);

            foreach (self::get_tables() as $table) {
                $short_key = $table_keys[ $table ];

                // Clear existing data
                $wpdb->query("TRUNCATE TABLE {$table}");

                if (! empty($snapshot['tables'][ $short_key ])) {
                    $rows = $snapshot['tables'][ $short_key ];

                    foreach ($rows as $row) {
                        // Remove AUTO_INCREMENT id from insert — let DB assign new id
                        unset($row['id']);

                        // Remove created_at/updated_at — let DB use DEFAULT
                        unset($row['created_at']);
                        unset($row['updated_at']);

                        $wpdb->insert($table, $row);
                    }

                    $restored_counts[ $short_key ] = count($rows);
                } else {
                    $restored_counts[ $short_key ] = 0;
                }
            }

            $wpdb->query('COMMIT');

            return [
                'restored_counts'  => $restored_counts,
                'emergency_backup' => $emergency,
            ];

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new \Exception('Khôi phục thất bại: ' . $e->getMessage());
        }
    }

    /**
     * Delete a snapshot file.
     *
     * @param string $filename Snapshot filename.
     */
    public static function delete(string $filename): void
    {
        $dir     = self::get_snapshot_dir();
        $filepath = $dir . '/' . basename($filename);

        // Validate filename format
        if (! preg_match('/^snapshot-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(-EMERGENCY)?\.json$/', basename($filename))) {
            throw new \Exception('Tên file snapshot không hợp lệ.');
        }

        if (! file_exists($filepath)) {
            throw new \Exception('File snapshot không tồn tại.');
        }

        if (! @unlink($filepath)) {
            throw new \Exception('Không thể xoá file snapshot.');
        }
    }

    /**
     * Delete snapshots older than $keep_days.
     * Always keeps at least 1 snapshot.
     *
     * @param int $keep_days Number of days to keep.
     * @return int Number of snapshots deleted.
     */
    public static function cleanup(int $keep_days = self::KEEP_DAYS_DEFAULT): int
    {
        $cutoff = strtotime("-{$keep_days} days");
        $list   = self::list();
        $deleted = 0;

        foreach ($list as $snapshot) {
            $ts = strtotime($snapshot['created_at']);
            if ($ts !== false && $ts < $cutoff) {
                try {
                    self::delete($snapshot['filename']);
                    $deleted++;
                } catch (\Throwable $e) {
                    // Log and continue
                    error_log('[HTWarehouse] Snapshot cleanup error: ' . $e->getMessage());
                }
            }
        }

        return $deleted;
    }

    /**
     * Format bytes to human-readable string.
     */
    public static function format_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    }
}
