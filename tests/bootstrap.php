<?php

/**
 * PHPUnit bootstrap for HTWarehouse unit tests.
 *
 * Goals:
 *   1. Stub WordPress globals (ABSPATH, wpdb, etc.) so plugin files can be
 *      loaded without a real WP installation.
 *   2. Register the plugin's PSR-4 autoloader so test classes can use
 *      `use HTWarehouse\Services\...` directly.
 */

// ── 1. WordPress stubs ───────────────────────────────────────────────────────

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (! defined('HTW_PLUGIN_DIR')) {
    define('HTW_PLUGIN_DIR', __DIR__ . '/../');
}

// Minimal wpdb stub — only the methods used by Services classes under test.
if (! class_exists('wpdb')) {
    class wpdb // phpcs:ignore
    {
        public string $prefix = 'wp_';

        public function prepare(string $query, ...$args): string
        {
            return vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%f'], $query), $args);
        }

        public function update(string $table, array $data, array $where, array $format = [], array $where_format = []): int|false
        {
            return 1; // Always succeed in unit tests — we test logic, not DB writes.
        }

        public function get_var(string $query): ?string
        {
            return null;
        }
    }
}

// Global $wpdb used by CostCalculator::add_stock / deduct_stock.
global $wpdb;
$wpdb = new wpdb();

// ── 2. Autoloader ────────────────────────────────────────────────────────────

require_once __DIR__ . '/../vendor/autoload.php';

// Manual class map for HTWarehouse\Services — these files use
// `defined('ABSPATH') || exit` guard so we must define ABSPATH first (done above).
spl_autoload_register(function (string $class): void {
    $prefix = 'HTWarehouse\\';
    $base   = __DIR__ . '/../includes/';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
