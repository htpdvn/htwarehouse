<?php

/**
 * HTWarehouse — Uninstall Handler
 *
 * Removes all plugin database tables, plugin options, scheduled cron events,
 * and uploads directory when the user deletes the plugin.
 *
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// ── D9 fix: Clear scheduled cron events before anything else ─────────────────
// Both hooks must be unscheduled so that if the plugin is re-installed later,
// no ghost cron events fire against the now-nonexistent plugin.
$hooks = ['htw_daily_snapshot', 'htw_log_pruner_daily'];
foreach ($hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp !== false) {
        wp_unschedule_event($timestamp, $hook);
    }
}

// ── Drop all plugin tables (child tables first, then parents) ─────────────────
global $wpdb;
$tables = [
    // Child tables (must be dropped first to satisfy FK constraints)
    $wpdb->prefix . 'htw_activity_logs',
    $wpdb->prefix . 'htw_return_items',
    $wpdb->prefix . 'htw_return_orders',
    $wpdb->prefix . 'htw_export_items',
    $wpdb->prefix . 'htw_export_orders',
    $wpdb->prefix . 'htw_import_items',
    $wpdb->prefix . 'htw_purchase_order_items',
    $wpdb->prefix . 'htw_po_payments',
    $wpdb->prefix . 'htw_purchase_orders',
    $wpdb->prefix . 'htw_import_batches',
    $wpdb->prefix . 'htw_suppliers',
    $wpdb->prefix . 'htw_products',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// ── Remove plugin options ──────────────────────────────────────────────────────
delete_option('htw_db_version');
delete_option('htw_snapshot_last_status');

// ── Clear any transients / caches ─────────────────────────────────────────────
delete_transient('htw_dashboard_cache');

// ── Remove snapshot uploads directory ──────────────────────────────────────────
$upload = wp_upload_dir();
$dir = $upload['basedir'] . '/htw-snapshots';
if (is_dir($dir)) {
    // Recursively delete all files and subdirectories
    $files = glob(rtrim($dir, '/') . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    // Remove directory itself
    @rmdir($dir);
}