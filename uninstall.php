<?php

/**
 * HTWarehouse — Uninstall Handler
 *
 * Removes all plugin database tables and plugin options when the user
 * deletes the plugin from the WordPress Plugins page.
 *
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// ── Drop all plugin tables ────────────────────────────────────────────────────
global $wpdb;
$tables = [
    $wpdb->prefix . 'htw_products',
    $wpdb->prefix . 'htw_import_batches',
    $wpdb->prefix . 'htw_import_items',
    $wpdb->prefix . 'htw_export_orders',
    $wpdb->prefix . 'htw_export_items',
    $wpdb->prefix . 'htw_purchase_orders',
    $wpdb->prefix . 'htw_purchase_order_items',
    $wpdb->prefix . 'htw_po_payments',
    $wpdb->prefix . 'htw_suppliers',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// ── Remove plugin options ──────────────────────────────────────────────────────
delete_option('htw_db_version');

// ── Clear any transients / caches ─────────────────────────────────────────────
delete_transient('htw_dashboard_cache');