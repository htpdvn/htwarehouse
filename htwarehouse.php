<?php

/**
 * Plugin Name: HTWarehouse
 * Plugin URI:  https://thebanker.online
 * Description: Phần mềm quản lý kho hàng: nhập hàng theo lô, xuất kho, tính giá vốn bình quân gia quyền (WAC), báo cáo lợi nhuận theo sản phẩm và kênh bán.
 * Version:     1.0.4
 * Author:      HTWarehouse
 * Text Domain: htwarehouse
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define('HTW_VERSION',    '1.1.2');
define('HTW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HTW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HTW_PLUGIN_FILE', __FILE__);

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register(function ($class) {
    $prefix = 'HTWarehouse\\';
    $base   = HTW_PLUGIN_DIR . 'includes/';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = str_replace($prefix, '', $class);
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    \HTWarehouse\Database::install();
    \HTWarehouse\Snapshot\SnapshotScheduler::get_instance()->register_hooks();
    \HTWarehouse\Snapshot\SnapshotScheduler::get_instance()->schedule();
});

register_deactivation_hook(__FILE__, function () {
    \HTWarehouse\Snapshot\SnapshotScheduler::get_instance()->clear_schedule();
    \HTWarehouse\Services\LogPruner::deactivate();
});

// ── Composer autoloader (TCPDF) ─────────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';
if (!class_exists('TCPDF')) {
    require_once __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
}

// ── Frontend Shortcode ────────────────────────────────────────────────────────
require_once HTW_PLUGIN_DIR . 'includes/Shortcode.php';

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    \HTWarehouse\Plugin::get_instance()->init();
});
