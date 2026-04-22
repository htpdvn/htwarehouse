<?php

namespace HTWarehouse;

use HTWarehouse\Pages\SnapshotPage;
use HTWarehouse\Pages\ActivityLogPage;
use HTWarehouse\Services\LogPruner;
use HTWarehouse\Snapshot\SnapshotScheduler;

defined('ABSPATH') || exit;

class Admin
{

    private static ?Admin $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        add_action('admin_menu',            [$this, 'register_menus']);
        add_action('admin_head',             [$this, 'render_menu_separator_css']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('script_loader_tag',      [$this, 'add_sri_attributes'], 10, 2);
        // Override WP admin viewport meta for responsive layout on HTW pages
        add_action('admin_init',             [$this, 'maybe_fix_viewport']);

        // AJAX handlers
        add_action('wp_ajax_htw_save_product',            [Pages\ProductsPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_delete_product',          [Pages\ProductsPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_get_product_categories',  [Pages\ProductsPage::class, 'ajax_get_categories']);
        add_action('wp_ajax_htw_export_categories',        [Pages\ProductsPage::class, 'ajax_export_categories']);
        add_action('wp_ajax_htw_import_categories',        [Pages\ProductsPage::class, 'ajax_import_categories']);
        add_action('wp_ajax_htw_save_import',        [Pages\ImportPage::class,   'ajax_save']);
        add_action('wp_ajax_htw_delete_import',      [Pages\ImportPage::class,   'ajax_delete']);
        add_action('wp_ajax_htw_confirm_import',     [Pages\ImportPage::class,   'ajax_confirm']);
        add_action('wp_ajax_htw_save_export',        [Pages\ExportPage::class,   'ajax_save']);
        add_action('wp_ajax_htw_delete_export',      [Pages\ExportPage::class,   'ajax_delete']);
        add_action('wp_ajax_htw_confirm_export',     [Pages\ExportPage::class,   'ajax_confirm']);
        add_action('wp_ajax_htw_export_detail',      [Pages\ExportPage::class,       'ajax_export_detail']);
        add_action('wp_ajax_htw_writeoff_list',      [Pages\ExportPage::class,       'ajax_writeoff_list']);
        add_action('wp_ajax_htw_writeoff_save',      [Pages\ExportPage::class,       'ajax_writeoff_save']);
        add_action('wp_ajax_htw_writeoff_confirm',   [Pages\ExportPage::class,       'ajax_writeoff_confirm']);
        add_action('wp_ajax_htw_writeoff_detail',    [Pages\ExportPage::class,       'ajax_writeoff_detail']);
        add_action('wp_ajax_htw_writeoff_delete',    [Pages\ExportPage::class,       'ajax_writeoff_delete']);
        add_action('wp_ajax_htw_save_return',        [Pages\ReturnOrderPage::class,   'ajax_save_return']);
        add_action('wp_ajax_htw_confirm_return',     [Pages\ReturnOrderPage::class,   'ajax_confirm_return']);
        add_action('wp_ajax_htw_return_list',        [Pages\ReturnOrderPage::class,   'ajax_return_list']);
        add_action('wp_ajax_htw_delete_return',      [Pages\ReturnOrderPage::class,   'ajax_delete_return']);
        add_action('wp_ajax_htw_report_data',        [Pages\ReportsPage::class,      'ajax_data']);
        add_action('wp_ajax_htw_export_pdf',           [Pages\ReportsPage::class,      'ajax_export_pdf']);
        add_action('wp_ajax_htw_dashboard_data',    [Pages\DashboardPage::class,   'ajax_data']);
        add_action('wp_ajax_htw_save_po',             [Pages\PurchaseOrderPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_confirm_po',         [Pages\PurchaseOrderPage::class, 'ajax_confirm']);
        add_action('wp_ajax_htw_po_send_to_import',   [Pages\PurchaseOrderPage::class, 'ajax_send_to_import']);
        add_action('wp_ajax_htw_delete_po',           [Pages\PurchaseOrderPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_po_record_payment',    [Pages\PurchaseOrderPage::class, 'ajax_record_payment']);
        add_action('wp_ajax_htw_po_edit_payment',      [Pages\PurchaseOrderPage::class, 'ajax_edit_payment']);
        add_action('wp_ajax_htw_po_delete_payment',    [Pages\PurchaseOrderPage::class, 'ajax_delete_payment']);
        add_action('wp_ajax_htw_save_supplier',            [Pages\SuppliersPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_delete_supplier',          [Pages\SuppliersPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_supplier_transactions',    [Pages\SuppliersPage::class, 'ajax_transactions']);

        // Snapshot AJAX
        add_action('wp_ajax_htw_snapshot_create',      [SnapshotPage::class, 'ajax_create']);
        add_action('wp_ajax_htw_snapshot_restore',     [SnapshotPage::class, 'ajax_restore']);
        add_action('wp_ajax_htw_snapshot_delete',      [SnapshotPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_snapshot_status',      [SnapshotPage::class, 'ajax_get_status']);
        add_action('wp_ajax_htw_snapshot_download',    [SnapshotPage::class, 'ajax_download']);
        add_action('wp_ajax_htw_snapshot_reschedule',  [SnapshotPage::class, 'ajax_reschedule']);

        // Activity log AJAX
        add_action('wp_ajax_htw_activity_log_data',   [ActivityLogPage::class, 'ajax_get_logs']);
        add_action('wp_ajax_htw_export_activity_log', [ActivityLogPage::class, 'ajax_export_csv']);
        add_action('wp_ajax_htw_log_prune_now',       [$this, 'ajax_prune_logs_now']);

        // Ensure daily snapshot cron is scheduled when admin loads
        SnapshotScheduler::get_instance()->register_hooks();

        // Ensure daily log pruner cron is scheduled
        LogPruner::get_instance()->register_hooks();
    }

    /**
     * Add SRI (Subresource Integrity) and crossorigin attributes to CDN scripts.
     *
     * @param string $tag  The generated <script> tag.
     * @param string $handle  WordPress script handle.
     * @return string Modified tag.
     */
    public function add_sri_attributes(string $tag, string $handle): string
    {
        // SRI (Subresource Integrity) hashes — verify at:
        //   https://www.jsdelivr.com/package/npm/chart.js@4.4.2
        //   https://www.jsdelivr.com/package/npm/alpinejs@3.13.5
        // Or run:  openssl dgst -sha384 -binary <(curl -s URL) | openssl base64 -A
        //
        // Uncomment and fill after verifying:
        // $sri_map = [
        //     'chartjs'  => ['integrity' => 'sha384-XXXXXXXX', 'crossorigin' => 'anonymous'],
        //     'alpinejs' => ['integrity' => 'sha384-XXXXXXXX', 'crossorigin' => 'anonymous'],
        // ];
        // if (isset($sri_map[$handle])) {
        //     $attrs = 'integrity="' . $sri_map[$handle]['integrity'] . '" '
        //            . 'crossorigin="' . $sri_map[$handle]['crossorigin'] . '"';
        //     $tag = str_replace(' src=', ' ' . $attrs . ' src=', $tag);
        // }

        return $tag;
    }

    public function register_menus(): void
    {
        add_menu_page(
            'HTWarehouse',
            'HTWarehouse',
            'manage_options',
            'htwarehouse',
            [Pages\DashboardPage::class, 'render'],
            'dashicons-store',
            56
        );

        add_submenu_page('htwarehouse', 'Dashboard',      'Dashboard',       'manage_options', 'htwarehouse',           [Pages\DashboardPage::class, 'render']);
        add_submenu_page('htwarehouse', 'Sản phẩm',       'Sản phẩm',        'manage_options', 'htw-products',          [Pages\ProductsPage::class,  'render']);
        add_submenu_page('htwarehouse', 'Nhà cung cấp',   'Nhà cung cấp (NCC)',    'manage_options', 'htw-suppliers',         [Pages\SuppliersPage::class, 'render']);
        add_submenu_page('htwarehouse', '',   '',                  'manage_options', 'htw-separator-2',       '__return_null');
        add_submenu_page('htwarehouse', 'Đặt hàng NCC',   'Đặt hàng NCC',        'manage_options', 'htw-purchase-orders',   [Pages\PurchaseOrderPage::class, 'render']);
        add_submenu_page('htwarehouse', 'Nhập kho',       'Nhập kho',        'manage_options', 'htw-imports',           [Pages\ImportPage::class,    'render']);
        add_submenu_page('htwarehouse', 'Bán', 'Xuất kho / Xuất bán',  'manage_options', 'htw-exports',           [Pages\ExportPage::class,    'render']);
        add_submenu_page('htwarehouse', '',   '',                  'manage_options', 'htw-separator-1',       '__return_null');
        add_submenu_page('htwarehouse', 'Báo cáo',        'Báo cáo',         'manage_options', 'htw-reports',           [Pages\ReportsPage::class,   'render']);
        add_submenu_page('htwarehouse', 'Sao lưu',        'Sao lưu',         'manage_options', 'htw-snapshots',         [SnapshotPage::class,        'render']);
        add_submenu_page('htwarehouse', 'Nhật ký',        'Nhật ký',         'manage_options', 'htw-activity-log',      [ActivityLogPage::class,     'render']);
    }

    public function render_menu_separator_css(): void
    {
?>
        <style>
            #adminmenu a[href$="page=htw-separator-1"],
            #adminmenu a[href$="page=htw-separator-2"] {
                display: block;
                height: 0 !important;
                padding: 0 !important;
                margin: 6px 12px !important;
                border-top: 1px solid rgba(255, 255, 255, 0.15) !important;
                pointer-events: none !important;
                cursor: default !important;
                overflow: hidden;
            }

            #adminmenu li:has(a[href$="page=htw-separator-1"]),
            #adminmenu li:has(a[href$="page=htw-separator-2"]) {
                margin: 0 !important;
                padding: 0 !important;
            }
        </style>
<?php
    }

    /**
     * Override WP admin viewport meta for HTWarehouse pages.
     * WP admin outputs: <meta name='viewport' content='width=1024' />
     * We need: width=device-width so mobile media queries fire correctly.
     * Uses ob_start to intercept the full page output and replace the viewport content.
     */
    public function maybe_fix_viewport(): void
    {
        $screen = get_current_screen();
        // get_current_screen() may return null during admin_init on non-screen requests
        if (! $screen) {
            // Fallback: check $_GET['page']
            $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
            $htw_prefixed = (strpos($page, 'htwarehouse') === 0 || strpos($page, 'htw-') === 0);
            if (! $htw_prefixed) return;
        } else {
            $htw_pages = [
                'toplevel_page_htwarehouse',
                'htwarehouse_page_htw-products',
                'htwarehouse_page_htw-suppliers',
                'htwarehouse_page_htw-imports',
                'htwarehouse_page_htw-exports',
                'htwarehouse_page_htw-reports',
                'htwarehouse_page_htw-purchase-orders',
                'htwarehouse_page_htw-snapshots',
                'htwarehouse_page_htw-activity-log',
            ];
            if (! in_array($screen->id, $htw_pages, true)) return;
        }

        ob_start(function (string $html): string {
            // Replace WP admin's fixed viewport with responsive one
            return str_replace(
                ["content='width=1024'", 'content="width=1024"',
                 "content='width=width=device-width'"], // guard double-replace
                'content="width=device-width, initial-scale=1.0"',
                $html
            );
        });
    }

    public function enqueue_assets(string $hook): void
    {
        $htw_pages = [
            'toplevel_page_htwarehouse',
            'htwarehouse_page_htw-products',
            'htwarehouse_page_htw-suppliers',
            'htwarehouse_page_htw-imports',
            'htwarehouse_page_htw-exports',
            'htwarehouse_page_htw-reports',
            'htwarehouse_page_htw-purchase-orders',
            'htwarehouse_page_htw-snapshots',
            'htwarehouse_page_htw-activity-log',
        ];

        if (! in_array($hook, $htw_pages, true)) {
            return;
        }

        // Chart.js — served locally to avoid CDN blocking issues
        wp_enqueue_script('chartjs', HTW_PLUGIN_URL . 'assets/js/chart.umd.min.js', [], '4.4.2', true);

        // WP Media uploader
        wp_enqueue_media();

        // Plugin assets — load BEFORE Alpine so component factories are registered before Alpine processes x-data.
        // Alpine runs after all deferred scripts, so htw-admin (defer) executes before alpinejs (also defer).
        wp_enqueue_style('htw-admin', HTW_PLUGIN_URL . 'assets/css/htw-admin.css', [], HTW_VERSION);
        wp_enqueue_script('htw-admin', HTW_PLUGIN_URL . 'assets/js/htw-admin.js', ['jquery', 'chartjs'], HTW_VERSION, true);

        // Alpine.js — served locally to avoid CDN blocking issues (same reason as chart.js).
        // Source: https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js
        // To update: curl -fsSL "https://cdn.jsdelivr.net/npm/alpinejs@X.Y.Z/dist/cdn.min.js" -o assets/js/alpinejs.min.js
        wp_enqueue_script('alpinejs', HTW_PLUGIN_URL . 'assets/js/alpinejs.min.js', [], '3.13.5', true);

        wp_localize_script('htw-admin', 'HTW', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('htw_nonce'),
            'currencySymbol' => 'đ',
        ]);
        // Alias for pages that use htwAdmin.ajaxUrl / htwAdmin.nonce (e.g. activity log)
        wp_localize_script('htw-admin', 'htwAdmin', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('htw_nonce'),
            'logMaxDays'   => \HTWarehouse\Services\LogPruner::MAX_AGE_DAYS,
            'logMaxRows'   => \HTWarehouse\Services\LogPruner::MAX_ROWS,
        ]);
    }

    // ── AJAX: Manual log pruning trigger ───────────────────────────────────────
    public function ajax_prune_logs_now(): void
    {
        check_ajax_referer('htw_nonce', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

        $result = \HTWarehouse\Services\LogPruner::run_now();

        wp_send_json_success([
            'deleted' => $result['deleted'],
            'before'  => $result['before'],
            'after'   => $result['after'],
            'message' => $result['deleted'] > 0
                ? "Đã xóa {$result['deleted']} bản ghi. Hiện còn {$result['after']} bản ghi."
                : "Không có bản ghi nào cần xóa. Tổng: {$result['after']} bản ghi.",
        ]);
    }
}
