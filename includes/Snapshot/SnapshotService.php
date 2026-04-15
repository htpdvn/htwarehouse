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
            $wpdb->prefix . 'htw_return_items',
            $wpdb->prefix . 'htw_activity_logs',
            // Parent tables
            $wpdb->prefix . 'htw_purchase_orders',
            $wpdb->prefix . 'htw_export_orders',
            $wpdb->prefix . 'htw_return_orders',
            $wpdb->prefix . 'htw_import_batches',
            $wpdb->prefix . 'htw_products',
            $wpdb->prefix . 'htw_suppliers',
        ];
    }

    /**
     * Short table names derived from get_tables() — single source of truth.
     * Eliminates hardcoded duplication between get_tables() and get_table_keys().
     */
    private static function get_table_keys(): array
    {
        global $wpdb;
        $keys = [];
        foreach (self::get_tables() as $table) {
            // Strip the WP prefix from the table name to get the short key.
            // e.g. "wp_htw_products" → "htw_products"
            $short_key = preg_replace('/^' . preg_quote($wpdb->prefix, '/') . '/', '', $table);
            $keys[ $table ] = $short_key;
        }
        return $keys;
    }

    /**
     * Tables whose timestamps must be preserved verbatim during restore (audit trail).
     * Derived dynamically from get_tables() — new audit tables are automatically included.
     */
    private static function get_preserve_timestamp_tables(): array
    {
        // Any table whose name contains 'log' or 'activity' is treated as an audit table.
        $keys = [];
        foreach (self::get_tables() as $table) {
            $short = str_replace($GLOBALS['wpdb']->prefix, '', $table);
            if (stripos($short, 'log') !== false || stripos($short, 'activity') !== false) {
                $keys[] = $short;
            }
        }
        return $keys;
    }

    /**
     * Ensure the snapshot directory exists AND is protected with .htaccess.
     * MUST be called before any write operation.
     * Idempotent — safe to call multiple times.
     */
    private static function ensure_snapshot_dir(): string
    {
        $upload = wp_upload_dir();
        $dir    = $upload['basedir'] . '/htw-snapshots';

        // Always ensure directory exists first
        if (! file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // S1 fix: .htaccess is written BEFORE returning — even if the directory
        // already existed from a pre-fix version and is missing .htaccess.
        // Previous code had two separate if/elseif branches; this collapses them
        // into a single unconditional guard that covers ALL cases.
        if (! file_exists($dir . '/.htaccess')) {
            // index.php only prevents directory listing; .htaccess is required
            // to deny direct GET requests to snapshot JSON files.
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($dir . '/.htaccess',
                "Options -Indexes\n" .
                "<FilesMatch \"\\.json$\">\n" .
                "    Order allow,deny\n" .
                "    Deny from all\n" .
                "</FilesMatch>\n"
            );
        }

        return $dir;
    }

    /**
     * Get the snapshot directory path.
     */
    private static function get_snapshot_dir(): string
    {
        return self::ensure_snapshot_dir();
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
        $filepath = $dir . '/' . basename($filename);

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
        $emergency      = self::create();
        $emergency_name = str_replace('.json', '-EMERGENCY.json', basename($emergency['filename']));
        rename($emergency['path'], $dir . '/' . $emergency_name);
        $emergency['filename'] = $emergency_name;

        // Step 2: Begin restore transaction — uses DELETE (not TRUNCATE)
        // because TRUNCATE is DDL and auto-commits in MySQL, breaking transaction protection.
        $wpdb->query('START TRANSACTION');

        try {
            $restored_counts = [];
            $table_keys     = self::get_table_keys();
            $preserve_keys  = self::get_preserve_timestamp_tables();
            $insert_errors  = [];

            // Restore in REVERSE order: parents first, then children.
            // get_tables() lists child tables first, parent tables last.
            // array_reverse() puts parents at the front so FK references are
            // satisfied before child rows (which reference them) are inserted.
            $tables_rev = array_reverse(self::get_tables());

            foreach ($tables_rev as $table) {
                $short_key = $table_keys[ $table ];

                // Clear existing data with DELETE (safe within transaction)
                $wpdb->query("DELETE FROM {$table}");

                if (! empty($snapshot['tables'][ $short_key ])) {
                    $rows     = $snapshot['tables'][ $short_key ];
                    $inserted = 0;
                    $row_num  = 0;  // S8 fix: separate counter so error messages always show correct row number

                    // Reset AUTO_INCREMENT to max(original IDs) + 1 so inserts
                    // reuse the original IDs and preserve FK relationships.
                    $max_id = 0;
                    foreach ($rows as $row) {
                        if (isset($row['id']) && (int) $row['id'] > $max_id) {
                            $max_id = (int) $row['id'];
                        }
                    }
                    if ($max_id > 0) {
                        $wpdb->query($wpdb->prepare(
                            "ALTER TABLE {$table} AUTO_INCREMENT = %d",
                            $max_id + 1
                        ));
                    }

                    foreach ($rows as $row) {
                        $row_num++;

                        // For audit-trail tables keep the original timestamps so the
                        // restored log is historically accurate. For all other tables,
                        // drop them and let the DB DEFAULT fire (avoids NOT NULL issues).
                        if (! in_array($short_key, $preserve_keys, true)) {
                            unset($row['created_at']);
                            unset($row['updated_at']);
                        }
                        // Keep original 'id' — preserves FK references in child tables

                        // S2 fix: use $inserted_result instead of $result to avoid
                        // shadowing the emergency backup array from Step 1 above.
                        $inserted_result = $wpdb->insert($table, $row);
                        if ($inserted_result === false) {
                            $insert_errors[] = sprintf(
                                '%s row %d: %s',
                                $short_key,
                                $row_num,  // S8 fix: always accurate, independent of $inserted counter
                                $wpdb->last_error
                            );
                        } else {
                            $inserted++;
                        }
                    }

                    $restored_counts[ $short_key ] = $inserted;
                } else {
                    $restored_counts[ $short_key ] = 0;
                }
            }

            // If any inserts failed, abort the restore so no partial data is committed
            if (! empty($insert_errors)) {
                $wpdb->query('ROLLBACK');
                $error_summary = implode('; ', array_slice($insert_errors, 0, 5));
                if (count($insert_errors) > 5) {
                    $error_summary .= sprintf(' (+%d lỗi khác)', count($insert_errors) - 5);
                }
                throw new \Exception('Lỗi khi chèn dữ liệu — rollback. Chi tiết: ' . $error_summary);
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
     * Also removes the .htaccess protection when the directory becomes empty.
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

        // S5 fix: remove .htaccess when directory becomes empty.
        // An empty directory without .htaccess is a potential directory-listing risk
        // on servers where .htaccess is not automatically inherited.
        // Safe: we re-create .htaccess on next write via ensure_snapshot_dir().
        $remaining = self::list();
        if (empty($remaining)) {
            $dir = self::get_snapshot_dir(); // ensures directory still exists
            @unlink($dir . '/.htaccess');
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
